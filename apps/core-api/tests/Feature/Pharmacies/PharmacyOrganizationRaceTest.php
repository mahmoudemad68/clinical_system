<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one organization when the same pharmacy actor onboards concurrently', function () {
    $session = pharmaciesActiveSession('race-user');
    $body = pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']);

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-organizations/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-prace-user-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-organizations/onboarding',
            'body' => $body,
            'access_token' => $session['token'],
            'idempotency_key' => 'clinic-test-idem-prace-user-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and(DB::table('pharmacy_organizations')->count())->toBe(1)
        ->and(DB::table('pharmacy_branches')->count())->toBe(1)
        ->and(DB::table('pharmacy_memberships')->count())->toBe(1)
        ->and((string) DB::table('pharmacy_memberships')->value('user_id'))->toBe($session['user_id']);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 201], true))->toBeTrue();
    }

    foreach ([$pair['left']['error_code'], $pair['right']['error_code']] as $code) {
        expect($code)->not->toBe('NOT_FOUND');
        expect($code)->not->toBe('INTERNAL_ERROR');
    }
});

it('creates one authoritative organization when two pharmacies onboard the same registration concurrently', function () {
    $left = pharmaciesActiveSession('race-reg-L');
    $right = pharmaciesActiveSession('race-reg-R');
    $registration = $left['payload']['registration'];

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-organizations/onboarding',
            'body' => pharmaciesOnboardingBody($registration, $left['payload']['phone']),
            'access_token' => $left['token'],
            'idempotency_key' => 'clinic-test-idem-prace-reg-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-organizations/onboarding',
            'body' => pharmaciesOnboardingBody($registration, $right['payload']['phone']),
            'access_token' => $right['token'],
            'idempotency_key' => 'clinic-test-idem-prace-reg-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and($statuses)->toContain(200)
        ->and(DB::table('pharmacy_organizations')->count())->toBe(1)
        ->and(DB::table('pharmacy_branches')->count())->toBe(1)
        ->and(DB::table('pharmacy_memberships')->count())->toBe(1)
        ->and([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('NOT_FOUND')
        ->and([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('INTERNAL_ERROR');

    $pending = $pair['left']['status'] === 200 ? $pair['left'] : $pair['right'];
    expect($pending['recovery_status'])->toBe('manual_review_required');
    $winnerId = (string) DB::table('pharmacy_memberships')->value('user_id');
    expect(in_array($winnerId, [$left['user_id'], $right['user_id']], true))->toBeTrue();
});
