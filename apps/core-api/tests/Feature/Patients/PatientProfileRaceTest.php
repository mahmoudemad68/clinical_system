<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Platform\Contracts\IdentityGenerator;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

afterEach(function (): void {
    DB::unprepared('TRUNCATE TABLE patient_demographic_revisions, patient_profiles, identity_national_ids, users RESTART IDENTITY CASCADE');
});

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

it('keeps one authoritative profile when unlinked create and onboarding race', function () {
    $session = patientsActiveSession('race-unlinked');
    $nid = $session['payload']['national_id'];
    $body = patientsDemographics($nid);

    $createdBy = app(IdentityGenerator::class)->next();
    app(CreateUnlinkedPatientProfile::class)->handle($body, 'staff', $createdBy);

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
        expect(in_array($status, [200, 201, 404], true))->toBeTrue();
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
