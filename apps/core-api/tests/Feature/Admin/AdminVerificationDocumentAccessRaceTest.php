<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationDocumentService;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('linearizes reviewer document-access issuance against a concurrent decision', function () {
    $draft = verificationPrepareDraftWithAvailableDocument('grant-race');
    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody($draft['case_version'], $draft['profile_version']),
        doctorsAuth($draft['session']['token']) + doctorsIdem('admin-grant-race-sub'),
    )->assertOk();

    $claimed = verificationClaimPending($draft, 'grant-race');
    $documentId = (string) DB::table('verification_documents')->where('case_id', $draft['case_id'])->value('id');
    expect($documentId)->not->toBe('');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'verification_document_grant',
            'reviewer_user_id' => $claimed['admin']['user_id'],
            'case_id' => $draft['case_id'],
            'document_id' => $documentId,
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

    $grantStatus = (int) $pair['left']['status'];
    $decisionStatus = (int) $pair['right']['status'];
    expect($decisionStatus)->toBe(200)
        ->and(in_array($grantStatus, [200, 404], true))->toBeTrue()
        ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe('approved');

    $grantAudits = DB::table('audit_events')->where('event_name', 'verification.document_access_granted')->orderBy('id')->get();
    $decisionAudits = DB::table('audit_events')->where('event_name', 'verification.decision_recorded')->orderBy('id')->get();
    expect($decisionAudits)->toHaveCount(1);

    if ($grantStatus === 200) {
        expect($grantAudits)->toHaveCount(1);
        $url = (string) ($pair['left']['url'] ?? '');
        expect($url)->toContain('/api/v1/verification-review-files/')
            ->and($url)->not->toContain('verification/c/')
            ->and($url)->not->toContain('verification/q/')
            ->and($url)->not->toContain('X-Amz-');
        adminVerificationDownload($url)->assertNotFound();
    } else {
        expect($grantAudits)->toHaveCount(0)
            ->and($pair['left']['url'] ?? null)->toBeNull();
    }

    expect(fn () => app(VerificationDocumentService::class)->issueReviewerReadGrant(
        $claimed['admin']['actor'],
        Identifier::fromTrusted($draft['case_id']),
        Identifier::fromTrusted($documentId),
    ))->toThrow(AuthorizationDenied::class);
});
