<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    expect($url)->toContain('X-Amz-Signature')
        ->and($url)->toContain($pending['canonical_storage_locator'])
        ->and($url)->not->toContain($pending['storage_locator'])
        ->and((string) $recording->temporaryUrlRefs[0]->storageLocator)->toBe($pending['canonical_storage_locator']);

    $get = Http::get($url);
    expect($get->successful())->toBeTrue()
        ->and($get->header('Content-Type'))->toContain('pdf');

    $audit = json_encode(DB::table('audit_events')->where('event_name', 'verification.document_access_granted')->get()->all(), JSON_THROW_ON_ERROR);
    expect($audit)->not->toContain($url)
        ->and($audit)->not->toContain($pending['canonical_storage_locator']);

    unset($claimed);
});
