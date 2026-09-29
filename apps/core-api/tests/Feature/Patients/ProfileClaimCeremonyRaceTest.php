<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('keeps one linked row when the same account claims concurrently', function () {
    profileClaimEnableIsolated();
    try {
        $ready = profileClaimPrepareFourFactor('pc-race-same');
        $body = profileClaimOnboardingBody($ready['nid'], $ready['credential']);
        $cfg = profileClaimIsolatedConfig();

        $pair = ConcurrentHttpPair::run(
            [
                'op' => 'http',
                'method' => 'POST',
                'uri' => '/api/v1/patients/onboarding',
                'body' => $body,
                'access_token' => $ready['session']['token'],
                'idempotency_key' => 'clinic-test-idem-pc-race-same-L',
                'config' => $cfg,
            ],
            [
                'op' => 'http',
                'method' => 'POST',
                'uri' => '/api/v1/patients/onboarding',
                'body' => $body,
                'access_token' => $ready['session']['token'],
                'idempotency_key' => 'clinic-test-idem-pc-race-same-R',
                'config' => $cfg,
            ],
        );

        expect(DB::table('patient_profiles')->count())->toBe(1)
            ->and((string) DB::table('patient_profiles')->value('user_id'))->toBe($ready['session']['user_id']);

        foreach ([$pair['left']['status'], $pair['right']['status']] as $status) {
            expect(in_array($status, [200, 201], true))->toBeTrue();
        }

        $recoveries = [$pair['left']['recovery_status'], $pair['right']['recovery_status']];
        expect($recoveries)->toContain('profile_ready');
        foreach ($recoveries as $recovery) {
            expect(in_array($recovery, ['profile_ready', 'manual_review_required'], true))->toBeTrue();
        }
    } finally {
        profileClaimDisableIsolated();
    }
});

it('keeps the first claimant when two accounts race the same unlinked profile', function () {
    profileClaimEnableIsolated();
    try {
        $ready = profileClaimPrepareFourFactor('pc-race-A');
        $other = patientsActiveSession('pc-race-B');
        DB::table('identity_national_ids')->where('user_id', $other['user_id'])->delete();
        $cfg = profileClaimIsolatedConfig();

        $pair = ConcurrentHttpPair::run(
            [
                'op' => 'http',
                'method' => 'POST',
                'uri' => '/api/v1/patients/onboarding',
                'body' => profileClaimOnboardingBody($ready['nid'], $ready['credential']),
                'access_token' => $ready['session']['token'],
                'idempotency_key' => 'clinic-test-idem-pc-race-A',
                'config' => $cfg,
            ],
            [
                'op' => 'http',
                'method' => 'POST',
                'uri' => '/api/v1/patients/onboarding',
                'body' => profileClaimOnboardingBody($ready['nid'], $ready['credential']),
                'access_token' => $other['token'],
                'idempotency_key' => 'clinic-test-idem-pc-race-B',
                'config' => $cfg,
            ],
        );

        expect(DB::table('patient_profiles')->where('status', '<>', 'merged')->count())->toBe(1)
            ->and((string) DB::table('patient_profiles')->value('user_id'))->toBe($ready['session']['user_id']);

        $leftReady = $pair['left']['recovery_status'] === 'profile_ready';
        $rightReady = $pair['right']['recovery_status'] === 'profile_ready';
        expect($leftReady || $rightReady)->toBeTrue()
            ->and($leftReady && $rightReady)->toBeFalse();
    } finally {
        profileClaimDisableIsolated();
    }
});
