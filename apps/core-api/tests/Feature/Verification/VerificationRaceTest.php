<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('keeps one authoritative decision under concurrent reviewers', function () {
    $draft = verificationPrepareDraftWithAvailableDocument('race-dec');
    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody($draft['case_version'], $draft['profile_version']),
        doctorsAuth($draft['session']['token']) + doctorsIdem('ver-race-sub'),
    )->assertOk();

    $claimed = verificationClaimPending($draft, 'race-l');
    $right = verificationSeedAdmin('race-r');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $draft['case_id'],
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_version' => $claimed['version'],
        ],
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $right['user_id'],
            'case_id' => $draft['case_id'],
            'decision' => 'rejected',
            'reason_code' => 'identity_mismatch',
            'expected_version' => $claimed['version'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('verification_decisions')->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(1);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 404, 409], true))->toBeTrue();
    }

    $codes = [$pair['left']['error_code'], $pair['right']['error_code']];
    expect($codes)->not->toContain('INTERNAL_ERROR');
});

it('replays identical concurrent decisions without a second row', function () {
    $draft = verificationPrepareDraftWithAvailableDocument('race-same');
    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody($draft['case_version'], $draft['profile_version']),
        doctorsAuth($draft['session']['token']) + doctorsIdem('ver-race-same-sub'),
    )->assertOk();

    $claimed = verificationClaimPending($draft, 'race-same');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $draft['case_id'],
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_version' => $claimed['version'],
        ],
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $draft['case_id'],
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_version' => $claimed['version'],
        ],
    );

    expect([$pair['left']['status'], $pair['right']['status']])->toBe([200, 200])
        ->and(DB::table('verification_decisions')->count())->toBe(1)
        ->and((string) DB::table('verification_decisions')->value('decision'))->toBe('approved');
});
