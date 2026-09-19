<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one profile when the same doctor onboards concurrently', function () {
    $specialty = doctorsSeedSpecialty('gp_race_user');
    $session = doctorsActiveSession('race-user');
    $body = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']);

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-drace-user-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-drace-user-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and(DB::table('doctor_profiles')->count())->toBe(1)
        ->and((string) DB::table('doctor_profiles')->value('user_id'))->toBe($session['user_id']);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 201], true))->toBeTrue();
    }

    foreach ([$pair['left']['error_code'], $pair['right']['error_code']] as $code) {
        expect($code)->not->toBe('NOT_FOUND');
        expect($code)->not->toBe('INTERNAL_ERROR');
    }
});

it('creates one authoritative profile when two doctors onboard the same national id concurrently', function () {
    $specialty = doctorsSeedSpecialty('gp_race_nid');
    $left = doctorsActiveSession('race-nid-L');
    $right = doctorsActiveSession('race-nid-R');
    $nid = $left['payload']['national_id'];
    $body = doctorsOnboardingBody($nid, $specialty['id']);

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/onboarding',
            'body' => $body,
            'access_token' => $left['token'],
            'idempotency_key' => 'clinic-test-idem-drace-nid-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/onboarding',
            'body' => $body,
            'access_token' => $right['token'],
            'idempotency_key' => 'clinic-test-idem-drace-nid-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and($statuses)->toContain(200)
        ->and(DB::table('doctor_profiles')->count())->toBe(1)
        ->and([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('NOT_FOUND')
        ->and([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('INTERNAL_ERROR');

    $pending = $pair['left']['status'] === 200 ? $pair['left'] : $pair['right'];
    expect($pending['recovery_status'])->toBe('manual_review_required');
    $winnerId = (string) DB::table('doctor_profiles')->value('user_id');
    expect(in_array($winnerId, [$left['user_id'], $right['user_id']], true))->toBeTrue();
});

it('creates one authoritative profile when two doctors onboard the same syndicate concurrently', function () {
    $specialty = doctorsSeedSpecialty('gp_race_syn');
    $left = doctorsActiveSession('race-syn-L');
    $right = doctorsActiveSession('race-syn-R');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/onboarding',
            'body' => doctorsOnboardingBody($left['payload']['national_id'], $specialty['id'], 'SYN-RACE'),
            'access_token' => $left['token'],
            'idempotency_key' => 'clinic-test-idem-drace-syn-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/doctors/onboarding',
            'body' => doctorsOnboardingBody($right['payload']['national_id'], $specialty['id'], 'SYN-RACE'),
            'access_token' => $right['token'],
            'idempotency_key' => 'clinic-test-idem-drace-syn-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and($statuses)->toContain(200)
        ->and(DB::table('doctor_profiles')->count())->toBe(1)
        ->and([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('INTERNAL_ERROR');

    $pending = $pair['left']['status'] === 200 ? $pair['left'] : $pair['right'];
    expect($pending['recovery_status'])->toBe('manual_review_required');
});
