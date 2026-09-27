<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Services\Time\FrozenClock;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Exceptions\ReviewerDocumentStreamAborted;
use Modules\Verification\Services\ReviewerDocumentUrlSigner;
use Tests\Support\ObserveThenFailStoreObject;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('admin verification signed document download', function () {
    it('streams canonical bytes through an application-signed URL with safe headers', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-ok');
        $admin = adminVerificationInsertAdmin('dl-ok');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();

        $logged = [];
        Log::listen(function (object $event) use (&$logged): void {
            $logged[] = ($event->message ?? '').' '.json_encode($event->context ?? [], JSON_THROW_ON_ERROR);
        });

        $recording = adminVerificationWrapObjectStore();
        $grant = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk();
        $url = (string) $grant->json('data.url');
        adminVerificationAssertApplicationDownloadUrl($url, $pending);
        expect($recording->temporaryUrlRefs)->toBe([]);

        $download = adminVerificationDownload($url)->assertOk();
        $body = adminVerificationDownloadBody($download);
        $document = DB::table('verification_documents')->where('id', $pending['document_id'])->first();
        assert($document !== null);
        $sha = (string) $document->sha256;
        $size = (int) $document->size_bytes;
        expect(hash('sha256', $body))->toBe($sha)
            ->and(strlen($body))->toBe($size)
            ->and((string) $download->headers->get('Content-Length'))->toBe((string) $size)
            ->and($recording->observeRefs)->not->toBe([])
            ->and($recording->observeWhileTransactionOpen)->toBe(0)
            ->and($recording->openStreamRefs)->not->toBe([])
            ->and((string) $recording->openStreamRefs[0]->storageLocator)->toBe($pending['canonical_storage_locator'])
            ->and((string) $recording->openStreamRefs[0]->storageLocator)->toStartWith('verification/c/')
            ->and((string) $recording->openStreamRefs[0]->storageLocator)->not->toStartWith('verification/q/');

        expect((string) $download->headers->get('Content-Type'))->toContain('application/pdf')
            ->and((string) $download->headers->get('Content-Disposition'))->toBe('attachment; filename="verification-document.pdf"')
            ->and((string) $download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and(strtolower((string) $download->headers->get('Cache-Control')))->toContain('no-store')
            ->and(strtolower((string) $download->headers->get('Cache-Control')))->toContain('private')
            ->and((string) $download->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and((string) $download->headers->get('Content-Disposition'))->not->toContain('inline')
            ->and($download->headers->get('Location'))->toBeNull();

        $wire = $url.$body.(string) $download->getContent();
        expect($wire)->not->toContain($pending['canonical_storage_locator'])
            ->and($wire)->not->toContain($pending['storage_locator'])
            ->and(json_encode($logged, JSON_THROW_ON_ERROR))->not->toContain($pending['canonical_storage_locator'])
            ->and(adminVerificationAuditJson('verification.document_access_granted'))->not->toContain($url)
            ->and(adminVerificationAuditJson('verification.document_access_granted'))->not->toContain($pending['canonical_storage_locator']);
    });

    it('ignores ingress overwrites and keeps serving canonical bytes', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-ingress');
        $admin = adminVerificationInsertAdmin('dl-ingress');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        $grant = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk();
        $url = (string) $grant->json('data.url');
        $canonicalSha = (string) DB::table('verification_documents')->where('id', $pending['document_id'])->value('sha256');

        app(StoreObject::class)->writeAt(
            verificationStoredRef($pending['upload_id']),
            'application/pdf',
            '%PDF-1.4 overwritten-ingress-bytes-must-not-be-served',
        );

        $body = adminVerificationDownloadBody(adminVerificationDownload($url)->assertOk());
        expect(hash('sha256', $body))->toBe($canonicalSha)
            ->and($body)->not->toContain('overwritten-ingress-bytes-must-not-be-served');
    });

    it('denies tampered, expired, rebound, decided, rejected, scanning, and missing-upload downloads', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-deny');
        $other = adminVerificationPendingCanonicalCase('dl-other');
        $missingIntent = adminVerificationPendingCase('dl-no-intent');
        $mixed = verificationOnboardDoctor('dl-mixed');
        $opened = verificationOpenCase($mixed['actor']);
        $rejected = verificationRegisterDocument((string) $opened->caseId, 'rejected', 'failed');
        $scanning = verificationRegisterDocument((string) $opened->caseId, 'quarantined', 'pending');
        $available = verificationRegisterDocument((string) $opened->caseId);
        verificationRegisterDocument((string) $opened->caseId, requirement: 'national_id_or_passport');
        $mixedCaseId = (string) $opened->caseId;
        test()->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
            doctorsAuth($mixed['session']['token']) + doctorsIdem('dl-mixed-sub'),
        )->assertOk();

        $admin = adminVerificationInsertAdmin('dl-deny');
        adminVerificationLogin($admin);
        $claimed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$other['case_id'].'/claim', [
            'expected_case_version' => $other['case_version'],
        ])->assertOk();
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$mixedCaseId.'/claim', [
            'expected_case_version' => (int) $opened->caseVersion + 1,
        ])->assertOk();
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$missingIntent['case_id'].'/claim', [
            'expected_case_version' => $missingIntent['case_version'],
        ])->assertOk();

        $frozen = new FrozenClock(app(Clock::class)->now());
        app()->instance(Clock::class, $frozen);
        $grant = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk();
        $url = (string) $grant->json('data.url');
        adminVerificationDownload($url)->assertOk();

        $tampered = preg_replace('/signature=[^&]+/', 'signature=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $url);
        expect($tampered)->toBeString();
        adminVerificationDownload((string) $tampered)->assertNotFound();

        $reboundDocument = str_replace($pending['document_id'], $other['document_id'], $url);
        adminVerificationDownload($reboundDocument)->assertNotFound();
        $reboundCase = str_replace($pending['case_id'], $other['case_id'], $url);
        adminVerificationDownload($reboundCase)->assertNotFound();

        $frozen->advance(121);
        adminVerificationDownload($url)->assertNotFound();
        app()->instance(Clock::class, new FrozenClock($frozen->now()->modify('-121 seconds')));

        $signer = app(ReviewerDocumentUrlSigner::class);
        $expires = app(Clock::class)->now()->modify('+120 seconds');
        adminVerificationDownload($signer->sign(
            Identifier::fromTrusted($mixedCaseId),
            Identifier::fromTrusted($rejected['document_id']),
            $expires,
        ))->assertNotFound();
        adminVerificationDownload($signer->sign(
            Identifier::fromTrusted($mixedCaseId),
            Identifier::fromTrusted($scanning['document_id']),
            $expires,
        ))->assertNotFound();
        $missingDocumentId = (string) DB::table('verification_documents')->where('case_id', $missingIntent['case_id'])->value('id');
        adminVerificationDownload($signer->sign(
            Identifier::fromTrusted($missingIntent['case_id']),
            Identifier::fromTrusted($missingDocumentId),
            $expires,
        ))->assertNotFound();
        unset($available);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
            ],
            adminVerificationIdem('dl-deny-decide'),
        )->assertOk();
        adminVerificationDownload($url)->assertNotFound();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertNotFound();
    });

    it('denies a same-size mutated canonical object before serving drifted bytes', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-drift-same');
        $admin = adminVerificationInsertAdmin('dl-drift-same');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        $url = (string) adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk()->json('data.url');

        $document = DB::table('verification_documents')->where('id', $pending['document_id'])->first();
        assert($document !== null);
        $trustedSha = (string) $document->sha256;
        $trustedSize = (int) $document->size_bytes;
        $mutated = verificationAlternatePdf();
        if (strlen($mutated) < $trustedSize) {
            $mutated .= str_repeat(' ', $trustedSize - strlen($mutated));
        } elseif (strlen($mutated) > $trustedSize) {
            $mutated = substr($mutated, 0, $trustedSize);
        }
        expect(strlen($mutated))->toBe($trustedSize)
            ->and(hash('sha256', $mutated))->not->toBe($trustedSha);

        app(StoreObject::class)->writeAt(
            verificationCanonicalRef($pending['upload_id']),
            'application/pdf',
            $mutated,
        );

        $download = adminVerificationDownload($url);
        expect($download->getStatusCode())->toBe(404);
        $body = (string) $download->getContent();
        expect(hash('sha256', $body))->not->toBe(hash('sha256', $mutated))
            ->and($body)->not->toContain($trustedSha)
            ->and($body)->not->toContain(hash('sha256', $mutated))
            ->and($body)->not->toContain($pending['canonical_storage_locator'])
            ->and($body)->not->toContain('verification/c/')
            ->and($body)->not->toContain('integrity_mismatch');

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->toContain('integrity_mismatch')
            ->and($metrics)->not->toContain($trustedSha)
            ->and($metrics)->not->toContain(hash('sha256', $mutated))
            ->and($metrics)->not->toContain($pending['canonical_storage_locator']);
    });

    it('denies a shorter canonical object than the persisted trusted size', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-drift-short');
        $admin = adminVerificationInsertAdmin('dl-drift-short');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        $url = (string) adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk()->json('data.url');

        $trustedSize = (int) DB::table('verification_documents')->where('id', $pending['document_id'])->value('size_bytes');
        $short = substr(verificationMinimalPdf(), 0, max(1, intdiv($trustedSize, 2)));
        expect(strlen($short))->toBeLessThan($trustedSize);

        app(StoreObject::class)->writeAt(
            verificationCanonicalRef($pending['upload_id']),
            'application/pdf',
            $short,
        );

        $download = adminVerificationDownload($url);
        expect($download->getStatusCode())->toBe(404)
            ->and(strlen((string) $download->getContent()))->not->toBe($trustedSize)
            ->and((string) $download->getContent())->not->toContain($pending['canonical_storage_locator']);
    });

    it('denies a longer canonical object than the persisted trusted size', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-drift-long');
        $admin = adminVerificationInsertAdmin('dl-drift-long');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        $url = (string) adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk()->json('data.url');

        $trustedSize = (int) DB::table('verification_documents')->where('id', $pending['document_id'])->value('size_bytes');
        $long = verificationMinimalPdf().str_repeat('X', 32);
        expect(strlen($long))->toBeGreaterThan($trustedSize);

        app(StoreObject::class)->writeAt(
            verificationCanonicalRef($pending['upload_id']),
            'application/pdf',
            $long,
        );

        $download = adminVerificationDownload($url);
        expect($download->getStatusCode())->toBe(404)
            ->and(strlen((string) $download->getContent()))->not->toBe(strlen($long))
            ->and((string) $download->getContent())->not->toContain($pending['canonical_storage_locator']);
    });

    it('does not complete a valid-looking document when the provider stream truncates after a matching observation', function () {
        $pending = adminVerificationPendingCanonicalCase('dl-stream-fail');
        $admin = adminVerificationInsertAdmin('dl-stream-fail');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        $url = (string) adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk()->json('data.url');

        $document = DB::table('verification_documents')->where('id', $pending['document_id'])->first();
        assert($document !== null);
        $trustedSha = (string) $document->sha256;
        $trustedSize = (int) $document->size_bytes;
        expect($trustedSize)->toBeGreaterThan(8);

        app()->instance(StoreObject::class, new ObserveThenFailStoreObject(app(StoreObject::class), 8, true));

        $download = adminVerificationDownload($url);
        expect($download->getStatusCode())->toBe(200)
            ->and((string) $download->headers->get('Content-Length'))->toBe((string) $trustedSize);

        $completed = false;
        $body = '';
        $buffers = ob_get_level();
        try {
            $body = $download->streamedContent();
            $completed = true;
        } catch (ReviewerDocumentStreamAborted) {
            while (ob_get_level() > $buffers) {
                ob_end_clean();
            }
        } catch (Throwable) {
            while (ob_get_level() > $buffers) {
                ob_end_clean();
            }
        }

        expect($completed && strlen($body) === $trustedSize && hash('sha256', $body) === $trustedSha)->toBeFalse()
            ->and(strlen($body))->toBeLessThan($trustedSize);
    });
});
