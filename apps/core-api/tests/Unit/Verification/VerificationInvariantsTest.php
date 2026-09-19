<?php

declare(strict_types=1);

use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationDecision;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
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

it('denies unknown case types, requirements, and reason codes', function () {
    $policy = app(VerificationPolicy::class);

    expect($policy->isKnownCaseType('doctor_verification'))->toBeTrue()
        ->and($policy->isKnownCaseType('pharmacy_verification'))->toBeFalse()
        ->and($policy->isKnownRequirement('doctor_verification', 'professional_id'))->toBeTrue()
        ->and($policy->isKnownRequirement('doctor_verification', 'unspecified_licence'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('approved', 'approved'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('evidence_incomplete', 'rejected'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('fraud_internal', 'rejected'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('approved', 'rejected'))->toBeFalse();
});
