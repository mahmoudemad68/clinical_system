<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Services\Adapters\DisabledScanObject;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\ScanVerdict;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\Adapters\DisabledTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\Adapters\ProcessingTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\VerificationUploadService;
use Modules\Verification\Support\VerificationPolicy;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\Support\FixtureScanObject;
use Tests\Support\RecordingStoreObject;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function verificationCleanupReasonCount(string $uploadId, string $reason): int
{
    $count = 0;
    foreach (DB::table('audit_events')->where('event_name', 'verification.upload_cleanup')->where('object_id', $uploadId)->pluck('metadata') as $metadata) {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
        } elseif (is_array($metadata)) {
            $decoded = $metadata;
        } else {
            $decoded = json_decode((string) json_encode($metadata), true);
        }
        if (is_array($decoded) && ($decoded['reason_code'] ?? '') === $reason) {
            $count++;
        }
    }

    return $count;
}

function verificationUploadJsonHasSecrets(string $body, string $locator, string $nationalId): bool
{
    return str_contains($body, $locator)
        || str_contains($body, $nationalId)
        || str_contains($body, 'storage_locator')
        || str_contains($body, 'object_key')
        || str_contains($body, 'X-Amz-Signature')
        || str_contains($body, 'national_id');
}

describe('verification upload intent HTTP', function () {
    it('lets a doctor create an upload for their own draft case', function () {
        $onboarded = verificationOnboardDoctor('up-own');
        $opened = verificationOpenCase($onboarded['actor']);
        $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-own-create');

        $created['response']->assertCreated()
            ->assertJsonPath('data.requirement_code', 'professional_id')
            ->assertJsonPath('data.state', 'uploading')
            ->assertJsonMissingPath('data.storage_locator')
            ->assertJsonMissingPath('data.object_id')
            ->assertJsonMissingPath('data.object_key')
            ->assertJsonMissingPath('data.bucket')
            ->assertJsonPath('data.upload_target.method', 'PUT');

        $body = (string) $created['response']->getContent();
        expect($body)->not->toContain($created['storage_locator'])
            ->and($body)->not->toContain($onboarded['national_id'])
            ->and($created['storage_locator'])->toStartWith('verification/q/')
            ->and(app(TrustedDocumentEvidenceIssuer::class))->toBeInstanceOf(DisabledTrustedDocumentEvidenceIssuer::class)
            ->and(app(ProcessingTrustedDocumentEvidenceIssuer::class))->toBeInstanceOf(ProcessingTrustedDocumentEvidenceIssuer::class);

        expect(DB::table('audit_events')->where('event_name', 'verification.upload_intent_created')->count())->toBe(1);
    });

    it('denies another doctor, unknown requirement, unknown fields, oversized size, and unsupported mime', function () {
        $left = verificationOnboardDoctor('up-bola-l');
        $right = verificationOnboardDoctor('up-bola-r');
        $opened = verificationOpenCase($left['actor']);

        test()->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($right['session']['token']) + doctorsIdem('up-bola-other'))
            ->assertNotFound();

        test()->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'not_a_requirement',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($left['session']['token']) + doctorsIdem('up-unknown-req'))
            ->assertStatus(422);

        test()->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
            'object_key' => 'evil',
        ], doctorsAuth($left['session']['token']) + doctorsIdem('up-mass'))
            ->assertStatus(422);

        test()->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 20_971_521,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($left['session']['token']) + doctorsIdem('up-oversize'))
            ->assertStatus(422);

        test()->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/zip',
        ], doctorsAuth($left['session']['token']) + doctorsIdem('up-zip'))
            ->assertStatus(422);

        test()->getJson('/api/v1/verification-uploads/00000000-0000-7000-8000-000000000000', doctorsAuth($left['session']['token']))
            ->assertNotFound();
    });

    it('replays identical intent creation and conflicts on payload mismatch', function () {
        $onboarded = verificationOnboardDoctor('up-idem');
        $opened = verificationOpenCase($onboarded['actor']);
        $headers = doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-idem-same');
        $payload = [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ];

        $first = test()->postJson('/api/v1/verification-uploads', $payload, $headers);
        $first->assertCreated();
        $second = test()->postJson('/api/v1/verification-uploads', $payload, $headers);
        $second->assertCreated();
        expect($second->json('data.upload_id'))->toBe($first->json('data.upload_id'))
            ->and($second->json('data.upload_target.method'))->toBe('PUT')
            ->and((string) $second->json('data.upload_target.url'))->not->toBe('')
            ->and((string) $second->json('data.upload_target.url'))->not->toContain('verification/q/')
            ->and((string) $second->json('data.upload_target.url'))->not->toContain('verification/c/')
            ->and(DB::table('verification_upload_intents')->count())->toBe(1);

        $replayedId = (string) $second->json('data.upload_id');
        verificationPutUploadBytes($replayedId, verificationMinimalPdf(), 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$replayedId.'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-idem-replay-complete'),
        )->assertOk()->assertJsonPath('data.state', 'quarantined');
        expect((string) DB::table('verification_upload_intents')->where('id', $replayedId)->value('canonical_storage_locator'))
            ->toStartWith('verification/c/');

        $afterComplete = test()->postJson('/api/v1/verification-uploads', $payload, $headers);
        $afterComplete->assertCreated()
            ->assertJsonPath('data.upload_id', $replayedId)
            ->assertJsonMissingPath('data.upload_target');

        $mismatch = test()->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 256,
            'declared_media_type' => 'application/pdf',
        ], $headers);
        $mismatch->assertStatus(409)->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');
    });

    it('denies upload after the case leaves draft', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('up-submitted');
        test()->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('up-sub-then'),
        )->assertOk();

        test()->postJson('/api/v1/verification-uploads', [
            'case_id' => $draft['case_id'],
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($draft['session']['token']) + doctorsIdem('up-after-sub'))
            ->assertStatus(409);
    });
});

describe('completion, validation, scan, and promotion', function () {
    it('promotes only a server-observed clean PDF to AVAILABLE', function () {
        verificationBindCleanScanner();
        $onboarded = verificationOnboardDoctor('up-promo');
        $opened = verificationOpenCase($onboarded['actor']);
        $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-promo-c');
        $created['response']->assertCreated();
        verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');

        $complete = test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-promo-done'),
        );
        $complete->assertOk()->assertJsonPath('data.state', 'quarantined');

        $replay = test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-promo-done-2'),
        );
        $replay->assertOk()->assertJsonPath('data.state', 'quarantined');

        verificationProcessUpload($created['upload_id']);

        $row = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
        $document = DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->first();
        expect((string) $row->state)->toBe('available')
            ->and((string) $row->observed_sha256)->toBe(hash('sha256', $created['bytes']))
            ->and((string) $row->detected_mime)->toBe('application/pdf')
            ->and((string) $row->canonical_storage_locator)->toStartWith('verification/c/')
            ->and((string) $row->canonical_storage_locator)->not->toBe((string) $row->storage_locator)
            ->and((string) $row->object_version)->not->toBe(hash('sha256', $created['bytes']))
            ->and($document)->not->toBeNull()
            ->and((string) $document->status)->toBe('available')
            ->and((string) $document->scan_status)->toBe('clean')
            ->and((string) $document->sha256)->toBe(hash('sha256', $created['bytes']));

        $status = test()->getJson(
            '/api/v1/verification-uploads/'.$created['upload_id'],
            doctorsAuth($onboarded['session']['token']),
        );
        $status->assertOk()
            ->assertJsonPath('data.state', 'available')
            ->assertJsonMissingPath('data.storage_locator')
            ->assertJsonMissingPath('data.sha256')
            ->assertJsonMissingPath('data.upload_target');

        $statusBody = (string) $status->getContent();
        expect(verificationUploadJsonHasSecrets($statusBody, $created['storage_locator'], $onboarded['national_id']))->toBeFalse();

        $payload = verificationOutboxPayload('verification.upload_completed');
        expect($payload)->toBeString()
            ->and($payload)->toContain($created['upload_id'])
            ->and($payload)->not->toContain($created['storage_locator'])
            ->and($payload)->not->toContain($onboarded['national_id']);

        $audit = json_encode(DB::table('audit_events')->where('event_name', 'verification.upload_available')->get(['event_name', 'metadata', 'object_id'])->all(), JSON_THROW_ON_ERROR);
        expect($audit)->not->toContain($created['storage_locator'])
            ->and($audit)->not->toContain($onboarded['national_id']);
    });

    it('fails complete when the object is missing, expired, or the client tries to set state', function () {
        $onboarded = verificationOnboardDoctor('up-complete-deny');
        $opened = verificationOpenCase($onboarded['actor']);
        $missing = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-missing');
        test()->postJson(
            '/api/v1/verification-uploads/'.$missing['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-missing-c'),
        )->assertStatus(422);

        $expired = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-expired');
        DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->update([
            'created_at' => now('UTC')->subMinutes(20)->format('Y-m-d H:i:s.uP'),
            'expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.uP'),
        ]);
        verificationPutUploadBytes($expired['upload_id'], $expired['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$expired['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-expired-c'),
        )->assertStatus(409);
        expect((string) DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->value('state'))->toBe('rejected')
            ->and((string) DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->value('rejection_reason'))->toBe('expired');

        $mass = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-complete-mass');
        test()->postJson(
            '/api/v1/verification-uploads/'.$mass['upload_id'].'/complete',
            ['available' => true, 'scan_status' => 'clean'],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-complete-mass'),
        )->assertStatus(422);
    });

    it('rejects zero-byte, oversized, mime mismatch, malformed, and unsupported objects', function () {
        verificationBindCleanScanner();
        $onboarded = verificationOnboardDoctor('up-validate');
        $opened = verificationOpenCase($onboarded['actor']);

        $zero = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-zero');
        verificationPutUploadBytes($zero['upload_id'], '', 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$zero['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-zero-c'),
        )->assertOk();
        verificationProcessUpload($zero['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $zero['upload_id'])->value('rejection_reason'))->toBe('zero_byte');

        $over = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-act-over', 'application/pdf', 32);
        verificationPutUploadBytes($over['upload_id'], str_repeat('A', 64), 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$over['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-act-over-c'),
        )->assertOk();
        verificationProcessUpload($over['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $over['upload_id'])->value('rejection_reason'))->toBe('oversized');

        $mismatch = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-mismatch');
        verificationPutUploadBytes($mismatch['upload_id'], verificationMinimalPng(), 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$mismatch['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-mismatch-c'),
        )->assertOk();
        verificationProcessUpload($mismatch['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $mismatch['upload_id'])->value('rejection_reason'))->toBe('mime_mismatch');

        $zip = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-unsup');
        verificationPutUploadBytes($zip['upload_id'], verificationZipBytes(), 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$zip['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-unsup-c'),
        )->assertOk();
        verificationProcessUpload($zip['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $zip['upload_id'])->value('rejection_reason'))->toBe('unsupported_format');

        $active = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-active', 'application/pdf', strlen(verificationActivePdf()));
        verificationPutUploadBytes($active['upload_id'], verificationActivePdf(), 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$active['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-active-c'),
        )->assertOk();
        verificationProcessUpload($active['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $active['upload_id'])->value('rejection_reason'))->toBe('malformed')
            ->and(DB::table('verification_documents')->where('upload_intent_id', $active['upload_id'])->count())->toBe(0);
    });

    it('rejects an antivirus test fixture and keeps scanner misses unavailable', function () {
        app()->instance(ScanObject::class, new FixtureScanObject);
        $onboarded = verificationOnboardDoctor('up-eicar');
        $opened = verificationOpenCase($onboarded['actor']);
        $infected = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-eicar-c', 'application/pdf', strlen(verificationEicarPdf()));
        verificationPutUploadBytes($infected['upload_id'], verificationEicarPdf(), 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$infected['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-eicar-done'),
        )->assertOk();
        verificationProcessUpload($infected['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $infected['upload_id'])->value('rejection_reason'))->toBe('malware_detected')
            ->and(DB::table('verification_documents')->count())->toBe(0);

        app()->instance(ScanObject::class, new DisabledScanObject);
        $miss = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-scan-miss');
        verificationPutUploadBytes($miss['upload_id'], $miss['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$miss['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-scan-miss-c'),
        )->assertOk();
        expect(fn () => verificationProcessUpload($miss['upload_id']))->toThrow(TransientProviderFailure::class);
        expect((string) DB::table('verification_upload_intents')->where('id', $miss['upload_id'])->value('state'))->toBe('scanning')
            ->and(DB::table('verification_documents')->where('upload_intent_id', $miss['upload_id'])->count())->toBe(0);
    });

    it('rejects TOCTOU replacement and does not mint evidence on promotion rollback', function () {
        $onboarded = verificationOnboardDoctor('up-toctou');
        $opened = verificationOpenCase($onboarded['actor']);
        $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-toctou-c');
        verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-toctou-done'),
        )->assertOk();
        $ref = verificationCanonicalRef($created['upload_id']);
        app()->instance(ScanObject::class, new class($ref) implements ScanObject
        {
            public function __construct(private StoredObjectRef $ref) {}

            public function scanStream(mixed $stream, int $sizeBytes): ScanVerdict
            {
                if (is_resource($stream)) {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 65_536);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                    }
                }
                unset($sizeBytes);
                app(StoreObject::class)->writeAt($this->ref, 'application/pdf', verificationAlternatePdf());

                return ScanVerdict::clean('fixture', 'test');
            }
        });
        verificationProcessUpload($created['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('rejection_reason'))->toBe('toctou_mismatch')
            ->and(DB::table('verification_documents')->count())->toBe(0);

        verificationBindCleanScanner();
        $rollback = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-rollback');
        verificationPutUploadBytes($rollback['upload_id'], $rollback['bytes'], 'application/pdf');
        $now = now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('verification_documents')->insert([
            'id' => app(IdentityGenerator::class)->next()->value,
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'other_slot',
            'object_id' => $rollback['object_id'],
            'sha256' => str_repeat('ab', 32),
            'detected_mime' => 'application/pdf',
            'size_bytes' => 12,
            'scan_status' => 'clean',
            'status' => 'available',
            'uploaded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        test()->postJson(
            '/api/v1/verification-uploads/'.$rollback['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-rollback-c'),
        )->assertOk();
        expect(fn () => verificationProcessUpload($rollback['upload_id']))->toThrow(StateConflict::class);
        expect((string) DB::table('verification_upload_intents')->where('id', $rollback['upload_id'])->value('state'))->not->toBe('available')
            ->and(DB::table('audit_events')->where('event_name', 'verification.upload_available')->where('object_id', $rollback['upload_id'])->count())->toBe(0);
    });

    it('hides another doctor status lookup and keeps a stale worker from promoting a rejected upload', function () {
        verificationBindCleanScanner();
        $left = verificationOnboardDoctor('up-priv-l');
        $right = verificationOnboardDoctor('up-priv-r');
        $opened = verificationOpenCase($left['actor']);
        $created = verificationCreateUploadIntent($left, (string) $opened->caseId, 'up-priv-c');
        test()->getJson('/api/v1/verification-uploads/'.$created['upload_id'], doctorsAuth($right['session']['token']))
            ->assertNotFound();
        test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            doctorsAuth($right['session']['token']) + doctorsIdem('up-priv-complete'),
        )->assertNotFound();

        $stale = verificationCreateUploadIntent($left, (string) $opened->caseId, 'up-stale');
        verificationPutUploadBytes($stale['upload_id'], $stale['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$stale['upload_id'].'/complete',
            [],
            doctorsAuth($left['session']['token']) + doctorsIdem('up-stale-c'),
        )->assertOk();
        DB::table('verification_upload_intents')->where('id', $stale['upload_id'])->update([
            'state' => 'rejected',
            'rejection_reason' => 'malware_detected',
            'cleanup_eligible_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
            'version' => DB::raw('version + 1'),
            'updated_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
        ]);
        verificationProcessUpload($stale['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $stale['upload_id'])->value('state'))->toBe('rejected')
            ->and(DB::table('verification_documents')->where('upload_intent_id', $stale['upload_id'])->count())->toBe(0);
    });

    it('ignores ingress overwrite after seal while promoting canonical bytes', function () {
        verificationBindCleanScanner();
        $onboarded = verificationOnboardDoctor('up-seal');
        $opened = verificationOpenCase($onboarded['actor']);
        $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-seal-c');
        $originalHash = hash('sha256', $created['bytes']);
        verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-seal-done'),
        )->assertOk()->assertJsonPath('data.state', 'quarantined');

        $ingress = verificationStoredRef($created['upload_id']);
        app(StoreObject::class)->writeAt($ingress, 'application/pdf', verificationAlternatePdf());
        verificationProcessUpload($created['upload_id']);

        $canonical = verificationCanonicalRef($created['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('state'))->toBe('available')
            ->and((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('observed_sha256'))->toBe($originalHash)
            ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash)
            ->and((string) DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->value('sha256'))->toBe($originalHash);
    });

    it('cleans expired uploading objects and expired AVAILABLE ingress without deleting canonical evidence', function () {
        verificationBindCleanScanner();
        $onboarded = verificationOnboardDoctor('up-clean');
        $opened = verificationOpenCase($onboarded['actor']);
        $expired = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-clean-exp');
        DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->update([
            'created_at' => now('UTC')->subMinutes(20)->format('Y-m-d H:i:s.uP'),
            'expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.uP'),
        ]);
        verificationPutUploadBytes($expired['upload_id'], $expired['bytes'], 'application/pdf');

        $kept = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-clean-keep');
        $originalHash = hash('sha256', $kept['bytes']);
        verificationPutUploadBytes($kept['upload_id'], $kept['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$kept['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-clean-keep-c'),
        )->assertOk();
        verificationProcessUpload($kept['upload_id']);

        $canonical = verificationCanonicalRef($kept['upload_id']);
        $ingress = verificationStoredRef($kept['upload_id']);
        expect((string) DB::table('verification_upload_intents')->where('id', $kept['upload_id'])->value('state'))->toBe('available')
            ->and(app(StoreObject::class)->exists($canonical))->toBeTrue()
            ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash);

        app(StoreObject::class)->writeAt($ingress, 'application/pdf', verificationAlternatePdf());
        expect(app(StoreObject::class)->exists($ingress))->toBeTrue()
            ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash);

        $beforeExpire = app(Clock::class)->now();
        Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);

        $expiredRow = DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->first();
        $retentionSeconds = app(VerificationPolicy::class)->cleanupRejectedAfterSeconds();
        $eligibleAt = new DateTimeImmutable((string) $expiredRow->cleanup_eligible_at);
        expect((string) $expiredRow->state)->toBe('rejected')
            ->and((string) $expiredRow->rejection_reason)->toBe('expired')
            ->and($expiredRow->cleanup_completed_at)->toBeNull()
            ->and($retentionSeconds)->toBe(86_400)
            ->and($eligibleAt->getTimestamp() - $beforeExpire->getTimestamp())->toBeGreaterThanOrEqual($retentionSeconds - 2)
            ->and($eligibleAt->getTimestamp() - $beforeExpire->getTimestamp())->toBeLessThanOrEqual($retentionSeconds + 2)
            ->and(app(StoreObject::class)->exists(verificationStoredRef($expired['upload_id'])))->toBeTrue()
            ->and(app(StoreObject::class)->exists($ingress))->toBeTrue()
            ->and(app(StoreObject::class)->exists($canonical))->toBeTrue();

        DB::table('verification_upload_intents')->where('id', $kept['upload_id'])->update([
            'created_at' => now('UTC')->subMinutes(20)->format('Y-m-d H:i:s.uP'),
            'expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.uP'),
        ]);
        Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
        Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);

        $document = DB::table('verification_documents')->where('upload_intent_id', $kept['upload_id'])->first();
        expect(app(StoreObject::class)->exists($ingress))->toBeFalse()
            ->and(app(StoreObject::class)->exists($canonical))->toBeTrue()
            ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash)
            ->and((string) $document->sha256)->toBe($originalHash)
            ->and((string) DB::table('verification_upload_intents')->where('id', $kept['upload_id'])->value('state'))->toBe('available')
            ->and(DB::table('verification_upload_intents')->where('id', $kept['upload_id'])->value('cleanup_completed_at'))->not->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'verification.upload_cleanup')->where('object_id', $kept['upload_id'])->count())->toBe(1);

        $caseVersion = (int) DB::table('verification_cases')->where('id', (string) $opened->caseId)->value('version');
        test()->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($caseVersion, $opened->profileVersion),
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-clean-submit'),
        )->assertOk();

        Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
        expect(app(StoreObject::class)->exists($canonical))->toBeTrue()
            ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash)
            ->and(DB::table('verification_documents')->where('upload_intent_id', $kept['upload_id'])->count())->toBe(1)
            ->and((string) DB::table('verification_documents')->where('upload_intent_id', $kept['upload_id'])->value('sha256'))->toBe($originalHash);
    });

    it('retains expired uploading objects until rejected cleanup eligibility and retries a failed deletion', function () {
        verificationBindCleanScanner();
        $onboarded = verificationOnboardDoctor('up-exp-retain');
        $opened = verificationOpenCase($onboarded['actor']);
        $expired = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-exp-retain-c');
        verificationPutUploadBytes($expired['upload_id'], $expired['bytes'], 'application/pdf');
        $ingress = verificationStoredRef($expired['upload_id']);
        DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->update([
            'created_at' => now('UTC')->subMinutes(20)->format('Y-m-d H:i:s.uP'),
            'expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.uP'),
        ]);

        $beforeExpire = app(Clock::class)->now();
        Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);

        $row = DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->first();
        $retentionSeconds = app(VerificationPolicy::class)->cleanupRejectedAfterSeconds();
        $eligibleAt = new DateTimeImmutable((string) $row->cleanup_eligible_at);
        expect((string) $row->state)->toBe('rejected')
            ->and((string) $row->rejection_reason)->toBe('expired')
            ->and($row->cleanup_completed_at)->toBeNull()
            ->and($retentionSeconds)->toBe(86_400)
            ->and($eligibleAt->getTimestamp() - $beforeExpire->getTimestamp())->toBeGreaterThanOrEqual($retentionSeconds - 2)
            ->and($eligibleAt->getTimestamp() - $beforeExpire->getTimestamp())->toBeLessThanOrEqual($retentionSeconds + 2)
            ->and(app(StoreObject::class)->exists($ingress))->toBeTrue()
            ->and(verificationCleanupReasonCount($expired['upload_id'], 'expired'))->toBe(1)
            ->and(verificationCleanupReasonCount($expired['upload_id'], 'rejected_object_removed'))->toBe(0);

        Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
        expect(app(StoreObject::class)->exists($ingress))->toBeTrue()
            ->and(DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->value('cleanup_completed_at'))->toBeNull()
            ->and(verificationCleanupReasonCount($expired['upload_id'], 'rejected_object_removed'))->toBe(0);

        DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->update([
            'cleanup_eligible_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.uP'),
        ]);

        $innerStore = app(StoreObject::class);
        $recording = new RecordingStoreObject($innerStore);
        $recording->failNextDeletes = 1;
        app()->instance(StoreObject::class, $recording);

        try {
            Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
            expect(app(StoreObject::class)->exists($ingress))->toBeTrue()
                ->and(DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->value('cleanup_completed_at'))->toBeNull()
                ->and(verificationCleanupReasonCount($expired['upload_id'], 'rejected_object_removed'))->toBe(0)
                ->and($recording->deleteAttempts)->toBe(1)
                ->and($recording->failNextDeletes)->toBe(0);

            Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
            expect(app(StoreObject::class)->exists($ingress))->toBeFalse()
                ->and(DB::table('verification_upload_intents')->where('id', $expired['upload_id'])->value('cleanup_completed_at'))->not->toBeNull()
                ->and(verificationCleanupReasonCount($expired['upload_id'], 'rejected_object_removed'))->toBe(1)
                ->and(verificationCleanupReasonCount($expired['upload_id'], 'expired'))->toBe(1)
                ->and($recording->deleteAttempts)->toBe(2);

            Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
            expect(app(StoreObject::class)->exists($ingress))->toBeFalse()
                ->and(verificationCleanupReasonCount($expired['upload_id'], 'rejected_object_removed'))->toBe(1)
                ->and($recording->deleteAttempts)->toBe(2);
        } finally {
            app()->instance(StoreObject::class, $innerStore);
        }
    });

    it('recovers a crash after canonical copy without allocating a second locator', function () {
        $onboarded = verificationOnboardDoctor('up-seal-crash');
        $opened = verificationOpenCase($onboarded['actor']);
        $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-seal-crash-c');
        verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');

        $innerStore = app(StoreObject::class);
        $recording = new RecordingStoreObject($innerStore);
        app()->instance(StoreObject::class, $recording);
        $originalAudit = app(AppendAuditEvent::class);
        app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent(
            $originalAudit,
            'verification.upload_completion_accepted',
        ));

        try {
            expect(fn () => app(VerificationUploadService::class)->completeDoctorUpload(
                $onboarded['actor'],
                Identifier::fromTrusted($created['upload_id']),
            ))->toThrow(TransientProviderFailure::class);

            $row = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
            $locator = (string) $row->canonical_storage_locator;
            $canonical = new StoredObjectRef('verification', $created['object_id'], $locator);
            expect((string) $row->state)->toBe('uploading')
                ->and($locator)->toStartWith('verification/c/')
                ->and($recording->locatorWasDurableBeforeCopy)->toBeTrue()
                ->and($recording->allocateCount)->toBe(1)
                ->and($recording->allocatedLocators)->toBe([$locator])
                ->and($recording->copiedLocators)->toBe([$locator])
                ->and($innerStore->exists($canonical))->toBeTrue()
                ->and(DB::table('outbox_events')->where('event_type', 'verification.upload_completed')->count())->toBe(0)
                ->and(DB::table('audit_events')->where('event_name', 'verification.upload_completion_accepted')->count())->toBe(0);

            $retry = app(VerificationUploadService::class)->completeDoctorUpload(
                $onboarded['actor'],
                Identifier::fromTrusted($created['upload_id']),
            );
            expect($retry->state)->toBe('quarantined')
                ->and($recording->allocateCount)->toBe(1)
                ->and((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('canonical_storage_locator'))->toBe($locator)
                ->and($innerStore->exists($canonical))->toBeTrue()
                ->and(DB::table('outbox_events')->where('event_type', 'verification.upload_completed')->count())->toBe(1);

            app(VerificationUploadService::class)->completeDoctorUpload(
                $onboarded['actor'],
                Identifier::fromTrusted($created['upload_id']),
            );
            expect($recording->allocateCount)->toBe(1)
                ->and(DB::table('outbox_events')->where('event_type', 'verification.upload_completed')->count())->toBe(1)
                ->and((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('canonical_storage_locator'))->toBe($locator);
        } finally {
            app()->instance(StoreObject::class, $innerStore);
            app()->instance(AppendAuditEvent::class, $originalAudit);
        }
    });

    it('retries AVAILABLE ingress cleanup after a failed deletion and audits success once', function () {
        verificationBindCleanScanner();
        $onboarded = verificationOnboardDoctor('up-clean-retry');
        $opened = verificationOpenCase($onboarded['actor']);
        $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-clean-retry-c');
        $originalHash = hash('sha256', $created['bytes']);
        verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');
        test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-clean-retry-done'),
        )->assertOk();
        verificationProcessUpload($created['upload_id']);

        $canonical = verificationCanonicalRef($created['upload_id']);
        $ingress = verificationStoredRef($created['upload_id']);
        app(StoreObject::class)->writeAt($ingress, 'application/pdf', verificationAlternatePdf());
        DB::table('verification_upload_intents')->where('id', $created['upload_id'])->update([
            'created_at' => now('UTC')->subMinutes(20)->format('Y-m-d H:i:s.uP'),
            'expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.uP'),
        ]);

        $innerStore = app(StoreObject::class);
        $recording = new RecordingStoreObject($innerStore);
        $recording->failNextDeletes = 1;
        app()->instance(StoreObject::class, $recording);

        try {
            Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
            expect(app(StoreObject::class)->exists($ingress))->toBeTrue()
                ->and(app(StoreObject::class)->exists($canonical))->toBeTrue()
                ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash)
                ->and(DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('cleanup_completed_at'))->toBeNull()
                ->and(DB::table('audit_events')->where('event_name', 'verification.upload_cleanup')->where('object_id', $created['upload_id'])->count())->toBe(0)
                ->and($recording->deleteAttempts)->toBe(1)
                ->and($recording->failNextDeletes)->toBe(0);

            Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
            expect(app(StoreObject::class)->exists($ingress))->toBeFalse()
                ->and(app(StoreObject::class)->exists($canonical))->toBeTrue()
                ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash)
                ->and((string) DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->value('sha256'))->toBe($originalHash)
                ->and(DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('cleanup_completed_at'))->not->toBeNull()
                ->and(DB::table('audit_events')->where('event_name', 'verification.upload_cleanup')->where('object_id', $created['upload_id'])->count())->toBe(1)
                ->and($recording->deleteAttempts)->toBe(2);

            Artisan::call('verification:reconcile-uploads', ['--limit' => 50]);
            expect(app(StoreObject::class)->exists($ingress))->toBeFalse()
                ->and(app(StoreObject::class)->exists($canonical))->toBeTrue()
                ->and(DB::table('audit_events')->where('event_name', 'verification.upload_cleanup')->where('object_id', $created['upload_id'])->count())->toBe(1)
                ->and($recording->deleteAttempts)->toBe(2);
        } finally {
            app()->instance(StoreObject::class, $innerStore);
        }
    });
});
