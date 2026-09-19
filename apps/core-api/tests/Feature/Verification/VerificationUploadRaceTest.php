<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('does not duplicate a verification document under concurrent completion and processing', function () {
    verificationBindCleanScanner();
    $onboarded = verificationOnboardDoctor('up-race');
    $opened = verificationOpenCase($onboarded['actor']);
    $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'up-race-c');
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            'body' => [],
            'access_token' => $onboarded['session']['token'],
            'idempotency_key' => 'clinic-test-idem-up-race-L',
            'object_store' => 'memory',
            'scanner' => 'fixture',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            'body' => [],
            'access_token' => $onboarded['session']['token'],
            'idempotency_key' => 'clinic-test-idem-up-race-R',
            'object_store' => 'memory',
            'scanner' => 'fixture',
        ],
    );

    foreach ([$pair['left']['status'], $pair['right']['status']] as $status) {
        expect(in_array($status, [200, 409], true))->toBeTrue();
    }
    expect(DB::table('verification_upload_intents')->where('id', $created['upload_id'])->count())->toBe(1);

    $process = ConcurrentHttpPair::run(
        [
            'op' => 'verification_process',
            'upload_id' => $created['upload_id'],
            'object_store' => 'memory',
            'scanner' => 'fixture',
        ],
        [
            'op' => 'verification_process',
            'upload_id' => $created['upload_id'],
            'object_store' => 'memory',
            'scanner' => 'fixture',
        ],
    );

    expect(in_array($process['left']['status'], [200, 409, 503], true))->toBeTrue()
        ->and(in_array($process['right']['status'], [200, 409, 503], true))->toBeTrue()
        ->and(DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->count())->toBe(1)
        ->and((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('state'))->toBe('available');
});

it('cannot mutate submitted evidence when submit races with scan', function () {
    $draft = verificationPrepareDraftWithAvailableDocument('up-race-sub');
    $created = verificationCreateUploadIntent(
        ['session' => $draft['session']],
        $draft['case_id'],
        'up-race-sub-c',
    );
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], 'application/pdf');
    test()->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        doctorsAuth($draft['session']['token']) + doctorsIdem('up-race-sub-done'),
    )->assertOk();

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/me/verification-submissions',
            'body' => verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            'access_token' => $draft['session']['token'],
            'idempotency_key' => 'clinic-test-idem-up-race-sub-L',
            'object_store' => 'memory',
            'scanner' => 'fixture',
        ],
        [
            'op' => 'verification_process',
            'upload_id' => $created['upload_id'],
            'object_store' => 'memory',
            'scanner' => 'fixture',
        ],
    );

    expect(in_array($pair['left']['status'], [200, 409], true))->toBeTrue();
    expect((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe('pending_review');

    $second = DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->first();
    if ($second !== null) {
        expect((string) $second->sha256)->toBe(hash('sha256', $created['bytes']));
    } else {
        expect((string) DB::table('verification_upload_intents')->where('id', $created['upload_id'])->value('state'))
            ->not->toBe('available');
    }

    $original = DB::table('verification_documents')->where('object_id', $draft['object_id'])->first();
    expect($original)->not->toBeNull()
        ->and((string) $original->status)->toBe('available');
});
