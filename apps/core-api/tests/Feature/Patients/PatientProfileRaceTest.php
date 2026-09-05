<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Platform\Contracts\IdentityGenerator;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one profile when the same user onboards concurrently', function () {
    $session = patientsActiveSession('race-user');
    $body = patientsDemographics($session['payload']['national_id']);

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/patients/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-race-user-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/patients/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-race-user-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and(DB::table('patient_profiles')->count())->toBe(1)
        ->and((string) DB::table('patient_profiles')->value('user_id'))->toBe($session['user_id']);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 201], true))->toBeTrue();
    }
});

it('creates one authoritative profile when two users onboard the same national id concurrently', function () {
    $left = patientsActiveSession('race-nid-L');
    $right = patientsActiveSession('race-nid-R');
    $nid = $left['payload']['national_id'];
    DB::table('identity_national_ids')->whereIn('user_id', [$left['user_id'], $right['user_id']])->delete();
    $body = patientsDemographics($nid);

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/patients/onboarding',
            'body' => $body,
            'access_token' => $left['token'],
            'idempotency_key' => 'clinic-test-idem-race-nid-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/patients/onboarding',
            'body' => $body,
            'access_token' => $right['token'],
            'idempotency_key' => 'clinic-test-idem-race-nid-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and($statuses)->toContain(200)
        ->and(DB::table('patient_profiles')->where('status', '<>', 'merged')->count())->toBe(1)
        ->and([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('NOT_FOUND');

    $pending = $pair['left']['status'] === 200 ? $pair['left'] : $pair['right'];
    expect($pending['recovery_status'])->toBe('manual_review_required');
});

it('keeps one authoritative profile when unlinked create and onboarding race', function () {
    $session = patientsActiveSession('race-unlinked');
    $nid = $session['payload']['national_id'];
    $body = patientsDemographics($nid);

    $createdBy = app(IdentityGenerator::class)->next();
    app(CreateUnlinkedPatientProfile::class)->handle(
        patientsUnlinkedActor($createdBy->value),
        $body,
        patientsCorrelationId(),
    );

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/patients/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-race-ul-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/patients/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-race-ul-R',
        ],
    );

    expect(DB::table('patient_profiles')->count())->toBe(1);
    foreach ([$pair['left']['status'], $pair['right']['status']] as $status) {
        expect($status)->toBe(200);
    }
});

it('rejects one concurrent demographic write that still carries the old version', function () {
    $session = patientsActiveSession('race-ver');
    $this->postJson(
        '/api/v1/patients/onboarding',
        patientsDemographics($session['payload']['national_id']),
        patientsAuth($session['token']) + patientsIdem('pon-race-ver'),
    )->assertCreated();

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'PATCH',
            'uri' => '/api/v1/patients/me/demographics',
            'body' => ['version' => 1, 'height_cm' => 171],
            'access_token' => $session['token'],
        ],
        [
            'op' => 'http',
            'method' => 'PATCH',
            'uri' => '/api/v1/patients/me/demographics',
            'body' => ['version' => 1, 'weight_kg' => 70],
            'access_token' => $session['token'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and($statuses)->toContain(409)
        ->and((int) DB::table('patient_profiles')->value('version'))->toBe(2);
});
