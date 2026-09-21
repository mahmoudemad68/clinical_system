<?php

declare(strict_types=1);

use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationDecision;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Services\ReviewerDocumentUrlSigner;
use Modules\Verification\Support\PharmacyVerificationCaseOutcome;
use Modules\Verification\Support\PharmacyVerificationSubmissionOutcome;
use Modules\Verification\Support\ReviewerDocumentAccessGrant;
use Modules\Verification\Support\VerificationDecisionOutcome;
use Modules\Verification\Support\VerificationPolicy;
use Tests\TestCase;

uses(TestCase::class);

it('allows only the documented case transitions', function () {
    expect(VerificationCaseStatus::Draft->canTransitionTo(VerificationCaseStatus::PendingReview))->toBeTrue()
        ->and(VerificationCaseStatus::PendingReview->canTransitionTo(VerificationCaseStatus::Approved))->toBeTrue()
        ->and(VerificationCaseStatus::PendingReview->canTransitionTo(VerificationCaseStatus::Rejected))->toBeTrue()
        ->and(VerificationCaseStatus::PendingReview->canTransitionTo(VerificationCaseStatus::ChangesRequested))->toBeTrue()
        ->and(VerificationCaseStatus::Draft->canTransitionTo(VerificationCaseStatus::Approved))->toBeFalse()
        ->and(VerificationCaseStatus::Approved->canTransitionTo(VerificationCaseStatus::PendingReview))->toBeFalse()
        ->and(VerificationCaseStatus::Rejected->canTransitionTo(VerificationCaseStatus::Draft))->toBeFalse()
        ->and(VerificationCaseStatus::ChangesRequested->canTransitionTo(VerificationCaseStatus::PendingReview))->toBeFalse();
});

it('treats only available plus clean documents as reviewable', function () {
    expect(VerificationDocumentStatus::Available->isReviewable(VerificationDocumentScanStatus::Clean))->toBeTrue()
        ->and(VerificationDocumentStatus::Available->isReviewable(VerificationDocumentScanStatus::Pending))->toBeFalse()
        ->and(VerificationDocumentStatus::Quarantined->isReviewable(VerificationDocumentScanStatus::Clean))->toBeFalse()
        ->and(VerificationDocumentStatus::Rejected->isReviewable(VerificationDocumentScanStatus::Clean))->toBeFalse();
});

it('maps decisions onto case statuses without inventing extra states', function () {
    expect(VerificationDecision::Approved->resultingCaseStatus())->toBe(VerificationCaseStatus::Approved)
        ->and(VerificationDecision::Rejected->resultingCaseStatus())->toBe(VerificationCaseStatus::Rejected)
        ->and(VerificationDecision::ChangesRequested->resultingCaseStatus())->toBe(VerificationCaseStatus::ChangesRequested)
        ->and(VerificationCaseStatus::values())->toBe([
            'draft',
            'pending_review',
            'changes_requested',
            'approved',
            'rejected',
        ]);
});

it('keeps the decision HTTP outcome inside the Platform idempotency pointer', function () {
    $encoded = json_encode((new VerificationDecisionOutcome(
        '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
        'approved',
        3,
        'approved',
        'approved',
    ))->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($encoded)->toBeString()
        ->and(strlen((string) $encoded))->toBeLessThanOrEqual(255)
        ->and($encoded)->not->toContain('notes')
        ->and($encoded)->not->toContain('documents');
});

it('omits the signed URL from reviewer grant debug output', function () {
    $grant = new ReviewerDocumentAccessGrant(
        '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a12',
        'https://objects.invalid/read/secret-grant?expires=1',
        '2026-09-20T00:00:02.000000Z',
        'application/pdf',
        2048,
    );

    $debug = json_encode($grant->__debugInfo(), JSON_THROW_ON_ERROR);
    expect($debug)->not->toContain('secret-grant')
        ->and($debug)->not->toContain('url')
        ->and($grant->toArray()['url'])->toContain('secret-grant');
});

it('keeps the pharmacy submit HTTP outcome inside the Platform idempotency pointer', function () {
    $encoded = json_encode((new PharmacyVerificationSubmissionOutcome(
        '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
        '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a11',
        'pending_review',
        2,
        2,
        'pending_review',
    ))->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($encoded)->toBeString()
        ->and(strlen((string) $encoded))->toBeLessThanOrEqual(255)
        ->and($encoded)->not->toContain('documents')
        ->and($encoded)->not->toContain('object_id')
        ->and($encoded)->not->toContain('legal_name');
});

it('keeps the pharmacy case-open HTTP outcome inside the Platform idempotency pointer', function () {
    $encoded = json_encode((new PharmacyVerificationCaseOutcome(
        '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
        '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a11',
        'draft',
        1,
        1,
    ))->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($encoded)->toBeString()
        ->and(strlen((string) $encoded))->toBeLessThanOrEqual(255)
        ->and($encoded)->not->toContain('documents');
});

it('uses generic server-owned filenames for reviewer downloads', function () {
    $policy = app(VerificationPolicy::class);

    expect($policy->reviewerDownloadFilename('application/pdf'))->toBe('verification-document.pdf')
        ->and($policy->reviewerDownloadFilename('image/jpeg'))->toBe('verification-document.jpg')
        ->and($policy->reviewerDownloadFilename('image/png'))->toBe('verification-document.png')
        ->and($policy->reviewerDownloadFilename('application/octet-stream'))->toBe('verification-document.bin')
        ->and($policy->reviewerDownloadChunkBytes())->toBe(65_536);
});

it('signs reviewer download URLs without storage locators', function () {
    $signer = app(ReviewerDocumentUrlSigner::class);
    $caseId = Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10');
    $documentId = Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a12');
    $expires = new DateTimeImmutable('+120 seconds', new DateTimeZone('UTC'));
    $url = $signer->sign($caseId, $documentId, $expires);
    $parts = parse_url($url);
    parse_str((string) ($parts['query'] ?? ''), $query);

    expect($url)->toContain('/api/v1/verification-review-files/'.$caseId->value.'/'.$documentId->value)
        ->and($url)->not->toContain('verification/c/')
        ->and($url)->not->toContain('verification/q/')
        ->and($url)->not->toContain('X-Amz-')
        ->and($signer->isValid($caseId->value, $documentId->value, (string) $query['expires'], (string) $query['signature'], new DateTimeImmutable('now', new DateTimeZone('UTC'))))->toBeTrue()
        ->and($signer->isValid($caseId->value, $documentId->value, (string) $query['expires'], 'tampered', new DateTimeImmutable('now', new DateTimeZone('UTC'))))->toBeFalse()
        ->and($signer->isValid($caseId->value, $documentId->value, (string) $query['expires'], (string) $query['signature'], $expires->modify('+1 second')))->toBeFalse();
});

it('denies unknown case types, requirements, and reason codes', function () {
    $policy = app(VerificationPolicy::class);

    expect($policy->isKnownCaseType('doctor_verification'))->toBeTrue()
        ->and($policy->isKnownCaseType('pharmacy_verification'))->toBeTrue()
        ->and($policy->isKnownCaseType('clinic_verification'))->toBeFalse()
        ->and($policy->isKnownRequirement('doctor_verification', 'professional_id'))->toBeTrue()
        ->and($policy->isKnownRequirement('pharmacy_verification', 'organization_registration_evidence'))->toBeTrue()
        ->and($policy->isKnownRequirement('doctor_verification', 'organization_registration_evidence'))->toBeFalse()
        ->and($policy->isKnownRequirement('pharmacy_verification', 'professional_id'))->toBeFalse()
        ->and($policy->isKnownRequirement('doctor_verification', 'unspecified_licence'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('approved', 'approved'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('evidence_incomplete', 'rejected'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('fraud_internal', 'rejected'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('approved', 'rejected'))->toBeFalse();
});
