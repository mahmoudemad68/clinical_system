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

/**
 * @param  array<string, mixed>|null  $headers
 * @return list<string>
 */
function liveGrantHeaderNames(?array $headers): array
{
    if ($headers === null) {
        return [];
    }

    return array_values(array_map(
        static fn (string $name): string => strtolower($name),
        array_keys($headers),
    ));
}

function assertClientSafeUploadTarget(mixed $target, string $storageLocator, string $responseBody): void
{
    expect($target)->toBeArray()
        ->and((string) ($target['method'] ?? ''))->toBe('PUT')
        ->and((string) ($target['url'] ?? ''))->not->toBe('')
        ->and($responseBody)->not->toContain($storageLocator)
        ->and($responseBody)->not->toContain('storage_locator')
        ->and($responseBody)->not->toContain('object_key')
        ->and($responseBody)->not->toContain('X-Amz-Signature');

    $names = liveGrantHeaderNames(is_array($target['headers'] ?? null) ? $target['headers'] : []);
    expect($names)->not->toContain('host')
        ->and($names)->not->toContain('connection')
        ->and($names)->not->toContain('transfer-encoding')
        ->and($names)->toContain('content-type');
}

it('promotes a real provider-backed upload and ignores later ingress overwrite', function () {
    $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
    $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
    $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
    clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

    try {
        verificationBindLiveObjectStore();
        expect(app(StoreObject::class))->toBeInstanceOf(S3StoreObject::class);
        app(StoreObject::class)->put('phase00', 'provider-probe', 'text/plain', 'probe');
    } catch (Throwable $e) {
        test()->fail('MinIO is reachable but the bucket, credentials, or policy is unusable: '.$e::class.' '.$e->getMessage());
    }

    if (clinicProviderRequired('CLINIC_REQUIRE_CLAMAV')) {
        clinicSkipUnlessTcp($this, '127.0.0.1', 3310, 'CLINIC_REQUIRE_CLAMAV', 'clamd');
        app()->instance(ScanObject::class, new ClamdScanObject(
            '127.0.0.1',
            3310,
            10_000,
            20_971_520,
            '1.4.6',
        ));
    } else {
        $socket = @fsockopen('127.0.0.1', 3310, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            app()->instance(ScanObject::class, new ClamdScanObject('127.0.0.1', 3310, 10_000, 20_971_520, '1.4.6'));
        } else {
            app()->instance(ScanObject::class, new FixtureScanObject);
        }
    }

    $onboarded = verificationOnboardDoctor('up-live-s3');
    $opened = verificationOpenCase($onboarded['actor']);
    $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-live-s3-c');
    $created['response']->assertCreated();
    $target = $created['response']->json('data.upload_target');
    assertClientSafeUploadTarget($target, $created['storage_locator'], (string) $created['response']->getContent());

    $put = Http::withHeaders($target['headers'] ?? [])
        ->withBody($created['bytes'], $created['mime'])
        ->put((string) $target['url']);
    expect($put->successful())->toBeTrue();

    test()->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-live-s3-done'),
    )->assertOk()->assertJsonPath('data.state', 'quarantined');

    $originalHash = hash('sha256', $created['bytes']);
    app(StoreObject::class)->writeAt(verificationStoredRef($created['upload_id']), 'application/pdf', verificationAlternatePdf());
    verificationProcessUpload($created['upload_id']);

    $row = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
    $canonical = verificationCanonicalRef($created['upload_id']);
    expect((string) $row->state)->toBe('available')
        ->and((string) $row->observed_sha256)->toBe($originalHash)
        ->and(app(StoreObject::class)->observe($canonical, 20_971_520)->sha256)->toBe($originalHash)
        ->and((string) DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->value('sha256'))->toBe($originalHash);

    $bucket = (string) config('filesystems.disks.s3.bucket');
    $anonymous = Http::withOptions(['http_errors' => false])->get(rtrim($endpoint, '/').'/'.$bucket.'/'.$canonical->key());
    expect($anonymous->successful())->toBeFalse()->and($anonymous->status())->not->toBe(200);
});

it('promotes a real provider-backed pharmacy verification upload without Host in the grant', function () {
    $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
    $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
    $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
    clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

    try {
        verificationBindLiveObjectStore();
        expect(app(StoreObject::class))->toBeInstanceOf(S3StoreObject::class);
        app(StoreObject::class)->put('phase00', 'provider-probe-pharmacy', 'text/plain', 'probe');
    } catch (Throwable $e) {
        test()->fail('MinIO is reachable but the bucket, credentials, or policy is unusable: '.$e::class.' '.$e->getMessage());
    }

    if (clinicProviderRequired('CLINIC_REQUIRE_CLAMAV')) {
        clinicSkipUnlessTcp($this, '127.0.0.1', 3310, 'CLINIC_REQUIRE_CLAMAV', 'clamd');
        app()->instance(ScanObject::class, new ClamdScanObject(
            '127.0.0.1',
            3310,
            10_000,
            20_971_520,
            '1.4.6',
        ));
    } else {
        $socket = @fsockopen('127.0.0.1', 3310, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            app()->instance(ScanObject::class, new ClamdScanObject('127.0.0.1', 3310, 10_000, 20_971_520, '1.4.6'));
        } else {
            app()->instance(ScanObject::class, new FixtureScanObject);
        }
    }

    $onboarded = pharmacyVerificationOnboard('up-live-pharm');
    $opened = pharmacyVerificationOpenCase($onboarded['actor']);
    $created = pharmacyVerificationCreateUploadIntent($onboarded, $opened->caseId, 'up-live-pharm-c');
    $created['response']->assertCreated();
    $target = $created['response']->json('data.upload_target');
    assertClientSafeUploadTarget($target, $created['storage_locator'], (string) $created['response']->getContent());
    pharmacyVerificationAssertNoCanaries((string) $created['response']->getContent(), $onboarded);

    $put = Http::withHeaders(is_array($target) ? ($target['headers'] ?? []) : [])
        ->withBody($created['bytes'], $created['mime'])
        ->put(is_array($target) ? (string) $target['url'] : '');
    expect($put->successful())->toBeTrue();

    test()->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('up-live-pharm-done'),
    )->assertOk()->assertJsonPath('data.state', 'quarantined');

    verificationProcessUpload($created['upload_id']);
    $row = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
    expect((string) $row->state)->toBe('available');
});
