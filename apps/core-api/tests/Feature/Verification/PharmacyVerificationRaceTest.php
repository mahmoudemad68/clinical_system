<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('opens exactly one pharmacy verification case under concurrent requests', function () {
    $onboarded = pharmacyVerificationOnboard('race-open');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-organizations/me/verification-cases',
            'body' => [],
            'access_token' => $onboarded['session']['token'],
            'idempotency_key' => 'clinic-test-idem-pver-open-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-organizations/me/verification-cases',
            'body' => [],
            'access_token' => $onboarded['session']['token'],
            'idempotency_key' => 'clinic-test-idem-pver-open-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('verification_cases')->where('applicant_id', $onboarded['organization_id'])->count())->toBe(1)
        ->and((string) DB::table('verification_cases')->where('applicant_id', $onboarded['organization_id'])->value('case_type'))->toBe('pharmacy_verification');

    foreach ($statuses as $status) {
        expect(in_array($status, [200], true))->toBeTrue();
    }
    expect([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('INTERNAL_ERROR');
});

it('assigns exactly one reviewer when two admins claim the same pharmacy case', function () {
    $pending = pharmacyVerificationPendingCase('claim-race');
    $left = verificationSeedAdmin('p-claim-l');
    $right = verificationSeedAdmin('p-claim-r');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_claim',
            'reviewer_user_id' => $left['user_id'],
            'case_id' => $pending['case_id'],
            'expected_version' => $pending['case_version'],
        ],
        [
            'op' => 'verification_claim',
            'reviewer_user_id' => $right['user_id'],
            'case_id' => $pending['case_id'],
            'expected_version' => $pending['case_version'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('verification_cases')->where('id', $pending['case_id'])->count())->toBe(1);

    $assigned = (string) DB::table('verification_cases')->where('id', $pending['case_id'])->value('assigned_reviewer_id');
    expect(in_array($assigned, [$left['user_id'], $right['user_id']], true))->toBeTrue();

    $winner = $assigned === $left['user_id'] ? $left : $right;
    $loser = $assigned === $left['user_id'] ? $right : $left;
    $version = (int) DB::table('verification_cases')->where('id', $pending['case_id'])->value('version');

    $winnerCase = app(VerificationService::class)->reviewerCase(
        $winner['actor'],
        Identifier::fromTrusted($pending['case_id']),
    );
    $loserCase = app(VerificationService::class)->reviewerCase(
        $loser['actor'],
        Identifier::fromTrusted($pending['case_id']),
    );
    expect($winnerCase->assignedToMe)->toBeTrue()
        ->and($winnerCase->documents)->not->toBe([])
        ->and($loserCase->assignedToMe)->toBeFalse()
        ->and($loserCase->documents)->toBe([]);

    expect(fn () => app(VerificationService::class)->recordDecision(
        $loser['actor'],
        Identifier::fromTrusted($pending['case_id']),
        'approved',
        'approved',
        $version,
    ))->toThrow(AuthorizationDenied::class);

    $documentId = Identifier::fromTrusted((string) DB::table('verification_documents')->where('case_id', $pending['case_id'])->value('id'));
    expect(fn () => app(VerificationDocumentService::class)->issueReviewerReadGrant(
        $loser['actor'],
        Identifier::fromTrusted($pending['case_id']),
        $documentId,
    ))->toThrow(AuthorizationDenied::class);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 409], true))->toBeTrue();
    }
});

it('keeps one authoritative pharmacy decision under concurrent reviewers', function () {
    $pending = pharmacyVerificationPendingCase('race-dec');
    $claimed = pharmacyVerificationClaimPending($pending, 'p-race-l');
    $right = verificationSeedAdmin('p-race-r');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $pending['case_id'],
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_version' => $claimed['version'],
        ],
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $right['user_id'],
            'case_id' => $pending['case_id'],
            'decision' => 'rejected',
            'reason_code' => 'identity_mismatch',
            'expected_version' => $claimed['version'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('verification_decisions')->where('case_id', $pending['case_id'])->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(1);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 404, 409], true))->toBeTrue();
    }
    expect([$pair['left']['error_code'], $pair['right']['error_code']])->not->toContain('INTERNAL_ERROR');

    $activeCount = (int) DB::table('pharmacy_organizations')->where('id', $pending['organization_id'])->where('status', 'active')->count();
    $decision = (string) DB::table('verification_decisions')->where('case_id', $pending['case_id'])->value('decision');
    if ($decision === 'approved') {
        expect($activeCount)->toBe(1);
        pharmacyVerificationAssertAggregate(
            $pending['organization_id'],
            PharmacyVerificationStatus::Approved->value,
            PharmacyOrganizationStatus::Active->value,
            'active',
            PharmacyMembershipStatus::Active->value,
        );
    } else {
        expect($activeCount)->toBe(0);
        pharmacyVerificationAssertAggregate(
            $pending['organization_id'],
            (string) DB::table('pharmacy_organizations')->where('id', $pending['organization_id'])->value('verification_status'),
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
    }
});

it('replays identical concurrent pharmacy decisions without a second row', function () {
    $pending = pharmacyVerificationPendingCase('race-same');
    $claimed = pharmacyVerificationClaimPending($pending, 'p-race-same');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $pending['case_id'],
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_version' => $claimed['version'],
        ],
        [
            'op' => 'verification_decide',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $pending['case_id'],
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_version' => $claimed['version'],
        ],
    );

    expect([$pair['left']['status'], $pair['right']['status']])->toBe([200, 200])
        ->and(DB::table('verification_decisions')->where('case_id', $pending['case_id'])->count())->toBe(1)
        ->and((string) DB::table('verification_decisions')->where('case_id', $pending['case_id'])->value('decision'))->toBe('approved')
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(1);

    pharmacyVerificationAssertAggregate(
        $pending['organization_id'],
        PharmacyVerificationStatus::Approved->value,
        PharmacyOrganizationStatus::Active->value,
        'active',
        PharmacyMembershipStatus::Active->value,
    );
});
