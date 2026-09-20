<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('assigns exactly one reviewer when two admins claim the same case', function () {
    $draft = verificationPrepareDraftWithAvailableDocument('claim-race');
    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody($draft['case_version'], $draft['profile_version']),
        doctorsAuth($draft['session']['token']) + doctorsIdem('admin-claim-race-sub'),
    )->assertOk();

    $left = verificationSeedAdmin('claim-l');
    $right = verificationSeedAdmin('claim-r');
    $expected = $draft['case_version'] + 1;

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_claim',
            'reviewer_user_id' => $left['user_id'],
            'case_id' => $draft['case_id'],
            'expected_version' => $expected,
        ],
        [
            'op' => 'verification_claim',
            'reviewer_user_id' => $right['user_id'],
            'case_id' => $draft['case_id'],
            'expected_version' => $expected,
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('verification_cases')->where('id', $draft['case_id'])->count())->toBe(1);

    $assigned = (string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('assigned_reviewer_id');
    expect(in_array($assigned, [$left['user_id'], $right['user_id']], true))->toBeTrue();

    $winner = $assigned === $left['user_id'] ? $left : $right;
    $loser = $assigned === $left['user_id'] ? $right : $left;
    $version = (int) DB::table('verification_cases')->where('id', $draft['case_id'])->value('version');

    $winnerCase = app(VerificationService::class)->reviewerCase(
        $winner['actor'],
        Identifier::fromTrusted($draft['case_id']),
    );
    $loserCase = app(VerificationService::class)->reviewerCase(
        $loser['actor'],
        Identifier::fromTrusted($draft['case_id']),
    );
    expect($winnerCase->assignedToMe)->toBeTrue()
        ->and($winnerCase->documents)->not->toBe([])
        ->and($loserCase->assignedToMe)->toBeFalse()
        ->and($loserCase->documents)->toBe([]);

    expect(fn () => app(VerificationService::class)->recordDecision(
        $loser['actor'],
        Identifier::fromTrusted($draft['case_id']),
        'approved',
        'approved',
        $version,
    ))->toThrow(AuthorizationDenied::class);

    $documentId = Identifier::fromTrusted((string) DB::table('verification_documents')->where('case_id', $draft['case_id'])->value('id'));
    expect(fn () => app(VerificationDocumentService::class)->issueReviewerReadGrant(
        $loser['actor'],
        Identifier::fromTrusted($draft['case_id']),
        $documentId,
    ))->toThrow(AuthorizationDenied::class);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 409], true))->toBeTrue();
    }
});
