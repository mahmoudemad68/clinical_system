<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Adapters\ClamdScanObject;
use Tests\Support\FixtureScanObject;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('promotes a real provider-backed upload and ignores later ingress overwrite', function () {
    $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
    $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
    $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
    clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

    try {
        verificationBindLiveObjectStore();
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
    expect($target)->toBeArray()
        ->and((string) ($target['url'] ?? ''))->not->toBe('')
        ->and((string) ($target['method'] ?? ''))->toBe('PUT');

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
