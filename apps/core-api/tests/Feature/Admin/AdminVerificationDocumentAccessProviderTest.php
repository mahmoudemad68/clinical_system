<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Adapters\ClamdScanObject;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use Tests\Support\FixtureScanObject;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('issues a live MinIO reviewer grant against the canonical object only', function () {
    $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
    $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
    $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
    clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

    try {
        verificationBindLiveObjectStore();
        app(StoreObject::class)->put('phase00', 'provider-probe-review', 'text/plain', 'probe');
    } catch (Throwable $e) {
        test()->fail('MinIO is reachable but the bucket, credentials, or policy is unusable: '.$e::class);
    }

    if (clinicProviderRequired('CLINIC_REQUIRE_CLAMAV')) {
        clinicSkipUnlessTcp($this, '127.0.0.1', 3310, 'CLINIC_REQUIRE_CLAMAV', 'clamd');
        app()->instance(ScanObject::class, new ClamdScanObject('127.0.0.1', 3310, 10_000, 20_971_520, '1.4.6'));
    } else {
        $socket = @fsockopen('127.0.0.1', 3310, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            app()->instance(ScanObject::class, new ClamdScanObject('127.0.0.1', 3310, 10_000, 20_971_520, '1.4.6'));
        } else {
            app()->instance(ScanObject::class, new FixtureScanObject);
        }
    }

    $pending = adminVerificationPendingCanonicalCase('doc-live');
    expect($pending['canonical_storage_locator'])->toStartWith('verification/c/');
    expect(app(StoreObject::class))->toBeInstanceOf(S3StoreObject::class);

    $admin = adminVerificationInsertAdmin('doc-live');
    adminVerificationLogin($admin);
    $claimed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
        'expected_case_version' => $pending['case_version'],
    ])->assertOk();

    $recording = adminVerificationWrapObjectStore();
    $grant = adminVerificationPostJson(
        '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
        [],
    )->assertOk();

    $url = (string) $grant->json('data.url');
    adminVerificationAssertApplicationDownloadUrl($url, $pending);
    expect($url)->not->toContain('X-Amz-Signature')
        ->and($recording->temporaryUrlRefs)->toBe([]);

    $canonical = verificationCanonicalRef($pending['upload_id']);
    $document = DB::table('verification_documents')->where('id', $pending['document_id'])->first();
    assert($document !== null);
    $persistedSha = (string) $document->sha256;
    $persistedSize = (int) $document->size_bytes;
    $observed = app(StoreObject::class)->observe($canonical, 20_971_520);
    expect($observed->exists)->toBeTrue()
        ->and($observed->sha256)->toBe($persistedSha)
        ->and($observed->sizeBytes)->toBe($persistedSize)
        ->and($observed->sha256)->not->toBe('');

    $download = adminVerificationDownload($url)->assertOk();
    expect((string) $download->headers->get('Content-Length'))->toBe((string) $persistedSize);
    $body = adminVerificationDownloadBody($download);
    expect(strlen($body))->toBe($persistedSize)
        ->and(hash('sha256', $body))->toBe($persistedSha)
        ->and(hash('sha256', $body))->toBe($observed->sha256)
        ->and((string) $download->headers->get('Content-Type'))->toContain('pdf')
        ->and((string) $download->headers->get('Content-Disposition'))->toBe('attachment; filename="verification-document.pdf"')
        ->and((string) $download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($recording->openStreamRefs)->not->toBe([])
        ->and((string) $recording->openStreamRefs[0]->storageLocator)->toBe($pending['canonical_storage_locator']);

    $audit = adminVerificationAuditJson('verification.document_access_granted');
    expect($audit)->not->toContain($url)
        ->and($audit)->not->toContain($pending['canonical_storage_locator'])
        ->and($audit)->not->toContain($pending['storage_locator']);

    unset($claimed);
});
