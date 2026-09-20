<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Support\Identifier;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('admin verification document access', function () {
    it('issues a short-lived canonical grant to the assigned reviewer only', function () {
        $pending = adminVerificationPendingCanonicalCase('doc-ok');
        $admin = adminVerificationInsertAdmin('doc-ok');
        adminVerificationLogin($admin);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertNotFound();

        $claimed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        expect($claimed->json('data.documents.0.document_id'))->toBe($pending['document_id']);

        $logged = [];
        Log::listen(function (object $event) use (&$logged): void {
            $logged[] = ($event->message ?? '').' '.json_encode($event->context ?? [], JSON_THROW_ON_ERROR);
        });

        $recording = adminVerificationWrapObjectStore();
        $before = DB::table('idempotency_keys')->count();
        $grant = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk();

        $url = (string) $grant->json('data.url');
        $expires = (string) $grant->json('data.expires_at');
        expect($grant->json('data.document_id'))->toBe($pending['document_id'])
            ->and($grant->json('data.detected_mime'))->toBe('application/pdf')
            ->and($grant->json('data.size_bytes'))->toBeGreaterThan(0)
            ->and($grant->json('data'))->not->toHaveKey('storage_locator')
            ->and($grant->json('data'))->not->toHaveKey('canonical_storage_locator')
            ->and($grant->json('data'))->not->toHaveKey('object_id')
            ->and($grant->json('data'))->not->toHaveKey('bucket')
            ->and(json_encode($grant->json('data'), JSON_THROW_ON_ERROR))->not->toContain($pending['storage_locator'])
            ->and(json_encode($grant->json('data'), JSON_THROW_ON_ERROR))->not->toContain($pending['canonical_storage_locator'])
            ->and(json_encode($grant->json('data'), JSON_THROW_ON_ERROR))->not->toContain($pending['national_id']);
        adminVerificationAssertApplicationDownloadUrl($url, $pending);

        $ttl = (new DateTimeImmutable($expires))->getTimestamp() - time();
        expect($ttl)->toBeGreaterThan(0)->and($ttl)->toBeLessThanOrEqual(300);

        expect($recording->temporaryUrlRefs)->toBe([])
            ->and($recording->openStreamRefs)->toBe([]);

        $audit = adminVerificationAuditJson('verification.document_access_granted');
        expect(DB::table('audit_events')->where('event_name', 'verification.document_access_granted')->count())->toBe(1);
        $metadata = $audit;
        expect($metadata)->toContain($pending['document_id'])
            ->and($metadata)->toContain('verification_review_access')
            ->and($metadata)->not->toContain($url)
            ->and($metadata)->not->toContain($pending['canonical_storage_locator'])
            ->and($metadata)->not->toContain($pending['storage_locator'])
            ->and($metadata)->not->toContain('X-Amz-Signature');

        expect(DB::table('outbox_events')->get()->pluck('payload')->implode(''))->not->toContain($url)
            ->and(DB::table('idempotency_keys')->count())->toBe($before)
            ->and(json_encode($logged, JSON_THROW_ON_ERROR))->not->toContain($url);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            ['document_id' => $pending['document_id']],
        )->assertStatus(422);

        adminVerificationLogout();
        $other = adminVerificationInsertAdmin('doc-other');
        adminVerificationLogin($other);
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertNotFound();
    });

    it('denies cross-case, guessed, rejected, scanning, and missing-upload documents', function () {
        $left = adminVerificationPendingCanonicalCase('doc-left');
        $right = adminVerificationPendingCanonicalCase('doc-right');
        $missingIntent = adminVerificationPendingCase('doc-no-intent');
        $mixed = verificationOnboardDoctor('doc-mixed');
        $opened = verificationOpenCase($mixed['actor']);
        $rejected = verificationRegisterDocument((string) $opened->caseId, 'rejected', 'failed');
        $scanning = verificationRegisterDocument((string) $opened->caseId, 'quarantined', 'pending');
        $available = verificationRegisterDocument((string) $opened->caseId);
        $mixedCaseId = (string) $opened->caseId;
        test()->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
            doctorsAuth($mixed['session']['token']) + doctorsIdem('doc-mixed-sub'),
        )->assertOk();

        $admin = adminVerificationInsertAdmin('doc-deny');
        adminVerificationLogin($admin);
        $claimLeft = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$left['case_id'].'/claim', [
            'expected_case_version' => $left['case_version'],
        ])->assertOk();
        $claimRight = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$right['case_id'].'/claim', [
            'expected_case_version' => $right['case_version'],
        ])->assertOk();
        $claimMixed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$mixedCaseId.'/claim', [
            'expected_case_version' => (int) $opened->caseVersion + 1,
        ])->assertOk();
        expect(collect($claimMixed->json('data.documents'))->pluck('document_id')->all())->toBe([$available['document_id']]);
        $claimMissing = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$missingIntent['case_id'].'/claim', [
            'expected_case_version' => $missingIntent['case_version'],
        ])->assertOk();

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$left['case_id'].'/documents/'.$right['document_id'].'/access',
            [],
        )->assertNotFound();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$left['case_id'].'/documents/'.Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c99')->value.'/access',
            [],
        )->assertNotFound();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$mixedCaseId.'/documents/'.$rejected['document_id'].'/access',
            [],
        )->assertNotFound();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$mixedCaseId.'/documents/'.$scanning['document_id'].'/access',
            [],
        )->assertNotFound();

        $missingDocumentId = (string) DB::table('verification_documents')->where('case_id', $missingIntent['case_id'])->value('id');
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$missingIntent['case_id'].'/documents/'.$missingDocumentId.'/access',
            [],
        )->assertNotFound();

        $approved = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$left['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimLeft->json('data.case_version'),
            ],
            adminVerificationIdem('doc-left-decide'),
        )->assertOk();
        expect($approved->json('data.case_status'))->toBe('approved');
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$left['case_id'].'/documents/'.$left['document_id'].'/access',
            [],
        )->assertNotFound();
    });

    it('rolls back issuance when document-access audit append fails', function () {
        $pending = adminVerificationPendingCanonicalCase('doc-audit-fail');
        $admin = adminVerificationInsertAdmin('doc-audit-fail');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();

        $inner = app(AppendAuditEvent::class);
        app()->instance(
            AppendAuditEvent::class,
            new FailOnceAppendAuditEvent($inner, 'verification.document_access_granted'),
        );

        $failed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        );
        expect($failed->status())->toBe(500)
            ->and($failed->json('data'))->toBeNull()
            ->and((string) $failed->getContent())->not->toContain($pending['canonical_storage_locator'])
            ->and((string) $failed->getContent())->not->toContain('/api/v1/verification-review-files/')
            ->and(DB::table('audit_events')->where('event_name', 'verification.document_access_granted')->count())->toBe(0);
    });
});
