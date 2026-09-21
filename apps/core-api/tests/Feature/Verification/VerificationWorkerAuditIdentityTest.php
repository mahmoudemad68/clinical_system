<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Audit\Services\Persistence\AuditDatabaseIdentity;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Adapters\ClamdScanObject;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use Modules\Platform\Services\Persistence\WorkerDatabaseIdentity;
use Tests\Support\DeferredAuditIdentityAppendAuditEvent;
use Tests\Support\FixtureScanObject;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->auditConnectionSnapshot = (array) config('database.connections.pgsql_audit');
});

afterEach(function (): void {
    if (app()->bound(WorkerDatabaseIdentity::class)) {
        app(WorkerDatabaseIdentity::class)->restore();
    }

    config(['database.connections.pgsql_audit' => $this->auditConnectionSnapshot]);
    restoreTestingAuditAppend();
});

/**
 * @return array{upload_id: string, bytes: string, mime: string}
 */
function workerAuditArrangeCompletedDoctorUpload(string $key): array
{
    verificationBindCleanScanner();
    $onboarded = verificationOnboardDoctor($key);
    $opened = verificationOpenCase($onboarded['actor']);
    $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, $key.'-c');
    $created['response']->assertCreated();
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');
    test()->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        doctorsAuth($onboarded['session']['token']) + doctorsIdem($key.'-done'),
    )->assertOk()->assertJsonPath('data.state', 'quarantined');

    return $created;
}

it('processes verification.upload_completed as clinic_worker using clinic_audit_writer', function () {
    skipUnlessWorkerConnection();
    skipUnlessAuditWriterConnection();

    $created = workerAuditArrangeCompletedDoctorUpload('up-worker-audit');
    $eventId = (string) DB::table('outbox_events')
        ->where('event_type', 'verification.upload_completed')
        ->whereRaw("payload->>'upload_id' = ?", [$created['upload_id']])
        ->value('event_id');
    expect($eventId)->not->toBe('');

    commitDefaultConnectionForCrossRoleVisibility();
    $identity = activateWorkerWithDedicatedAudit();

    expect($identity->currentRole())->toBe('clinic_worker')
        ->and($identity->currentRole('pgsql_audit'))->toBe('clinic_audit_writer')
        ->and(workerNeverGainedAuditExecute())->toBeTrue();

    test()->artisan('outbox:work', ['--once' => true])->assertSuccessful();

    expect($identity->lastVerifiedRole())->toBe('clinic_worker')
        ->and($identity->isActive())->toBeFalse()
        ->and(auditConnectionCurrentUser())->toBe('clinic_audit_writer');

    $outbox = DB::table('outbox_events')->where('event_id', $eventId)->first();
    $upload = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
    $document = DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->first();

    expect((string) $outbox->status)->toBe('PROCESSED')
        ->and((string) $outbox->status)->not->toBe('DEAD_LETTER')
        ->and((int) $outbox->attempts)->toBeLessThan(8)
        ->and((string) $upload->state)->toBe('available')
        ->and($document)->not->toBeNull()
        ->and((string) $document->status)->toBe('available')
        ->and(DB::connection('pgsql_audit')->table('audit_events')->where('object_id', $created['upload_id'])->count())->toBeGreaterThan(0)
        ->and(workerNeverGainedAuditExecute())->toBeTrue();

    $identity->restore();
    expect(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();
});

it('fails upload processing when the audit connection identity is wrong instead of marking available', function () {
    skipUnlessWorkerConnection();
    skipUnlessAuditWriterConnection();

    $created = workerAuditArrangeCompletedDoctorUpload('up-worker-audit-deny');
    $eventId = (string) DB::table('outbox_events')
        ->where('event_type', 'verification.upload_completed')
        ->whereRaw("payload->>'upload_id' = ?", [$created['upload_id']])
        ->value('event_id');

    commitDefaultConnectionForCrossRoleVisibility();

    $workerUrl = postgresRoleUrl((array) config('database.connections.pgsql_worker'));
    config(['database.connections.pgsql_audit.url' => $workerUrl]);
    DB::purge('pgsql_audit');
    app()->forgetInstance(AuditDatabaseIdentity::class);

    $identity = app(WorkerDatabaseIdentity::class);
    $identity->activate();
    app()->forgetInstance(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new DeferredAuditIdentityAppendAuditEvent);

    expect($identity->currentRole())->toBe('clinic_worker')
        ->and(auditConnectionCurrentUser())->toBe('clinic_worker');

    test()->artisan('outbox:work', ['--once' => true])->assertSuccessful();

    $outbox = DB::table('outbox_events')->where('event_id', $eventId)->first();
    $upload = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();

    expect((string) $outbox->status)->toBe('FAILED')
        ->and((string) $outbox->status)->not->toBe('PROCESSED')
        ->and((string) $outbox->status)->not->toBe('DEAD_LETTER')
        ->and((int) $outbox->attempts)->toBe(1)
        ->and((string) $upload->state)->not->toBe('available')
        ->and(DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->exists())->toBeFalse()
        ->and(workerNeverGainedAuditExecute())->toBeTrue();
});

it('promotes a real MinIO upload through the worker and dedicated audit writer', function () {
    skipUnlessWorkerConnection();
    skipUnlessAuditWriterConnection();

    $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
    $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
    $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
    clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

    try {
        verificationBindLiveObjectStore();
        expect(app(StoreObject::class))->toBeInstanceOf(S3StoreObject::class);
        app(StoreObject::class)->put('phase00', 'worker-audit-probe', 'text/plain', 'probe');
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

    $onboarded = verificationOnboardDoctor('up-worker-minio');
    $opened = verificationOpenCase($onboarded['actor']);
    $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-worker-minio-c');
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
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('up-worker-minio-done'),
    )->assertOk()->assertJsonPath('data.state', 'quarantined');

    $eventId = (string) DB::table('outbox_events')
        ->where('event_type', 'verification.upload_completed')
        ->whereRaw("payload->>'upload_id' = ?", [$created['upload_id']])
        ->value('event_id');

    commitDefaultConnectionForCrossRoleVisibility();
    $identity = activateWorkerWithDedicatedAudit();

    expect($identity->currentRole())->toBe('clinic_worker')
        ->and($identity->currentRole('pgsql_audit'))->toBe('clinic_audit_writer')
        ->and(app(StoreObject::class))->toBeInstanceOf(S3StoreObject::class)
        ->and(workerNeverGainedAuditExecute())->toBeTrue();

    test()->artisan('outbox:work', ['--once' => true])->assertSuccessful();

    $outbox = DB::table('outbox_events')->where('event_id', $eventId)->first();
    $upload = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();

    expect($identity->lastVerifiedRole())->toBe('clinic_worker')
        ->and((string) $outbox->status)->toBe('PROCESSED')
        ->and((string) $outbox->status)->not->toBe('DEAD_LETTER')
        ->and((int) $outbox->attempts)->toBeLessThan(8)
        ->and((string) $upload->state)->toBe('available')
        ->and(DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->exists())->toBeTrue()
        ->and(DB::connection('pgsql_audit')->table('audit_events')->where('event_name', 'verification.upload_available')->where('object_id', $created['upload_id'])->exists())->toBeTrue()
        ->and(workerNeverGainedAuditExecute())->toBeTrue();

    expect(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();
});
