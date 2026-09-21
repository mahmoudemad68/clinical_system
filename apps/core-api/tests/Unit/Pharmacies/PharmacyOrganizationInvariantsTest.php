<?php

declare(strict_types=1);

use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Support\LegalRegistration;
use Modules\Pharmacies\Support\PharmacyCoordinates;
use Modules\Platform\Exceptions\InvalidValueObject;
use Tests\TestCase;

uses(TestCase::class);

it('never treats verification, organization, branch, or membership state as a business grant', function () {
    foreach (PharmacyVerificationStatus::cases() as $status) {
        expect($status->confersBusinessCapability())->toBeFalse();
    }
    foreach (PharmacyOrganizationStatus::cases() as $status) {
        expect($status->confersBusinessCapability())->toBeFalse();
    }
    foreach (PharmacyBranchStatus::cases() as $status) {
        expect($status->confersBusinessCapability())->toBeFalse();
    }
    foreach (PharmacyMembershipRole::cases() as $role) {
        expect($role->confersBusinessCapability())->toBeFalse();
    }
    foreach (PharmacyMembershipStatus::cases() as $status) {
        expect($status->confersBusinessCapability())->toBeFalse();
    }
});

it('allows a new verification case only from draft, changes requested, or rejected', function () {
    expect(PharmacyVerificationStatus::Draft->allowsNewVerificationCase())->toBeTrue()
        ->and(PharmacyVerificationStatus::ChangesRequested->allowsNewVerificationCase())->toBeTrue()
        ->and(PharmacyVerificationStatus::Rejected->allowsNewVerificationCase())->toBeTrue()
        ->and(PharmacyVerificationStatus::PendingReview->allowsNewVerificationCase())->toBeFalse()
        ->and(PharmacyVerificationStatus::Approved->allowsNewVerificationCase())->toBeFalse()
        ->and(PharmacyVerificationStatus::Suspended->allowsNewVerificationCase())->toBeFalse();
});

it('allows only the documented pharmacy verification status transitions', function () {
    expect(PharmacyVerificationStatus::Draft->canTransitionTo(PharmacyVerificationStatus::PendingReview))->toBeTrue()
        ->and(PharmacyVerificationStatus::PendingReview->canTransitionTo(PharmacyVerificationStatus::Approved))->toBeTrue()
        ->and(PharmacyVerificationStatus::PendingReview->canTransitionTo(PharmacyVerificationStatus::Rejected))->toBeTrue()
        ->and(PharmacyVerificationStatus::PendingReview->canTransitionTo(PharmacyVerificationStatus::ChangesRequested))->toBeTrue()
        ->and(PharmacyVerificationStatus::ChangesRequested->canTransitionTo(PharmacyVerificationStatus::PendingReview))->toBeTrue()
        ->and(PharmacyVerificationStatus::Rejected->canTransitionTo(PharmacyVerificationStatus::PendingReview))->toBeTrue()
        ->and(PharmacyVerificationStatus::Draft->canTransitionTo(PharmacyVerificationStatus::Approved))->toBeFalse()
        ->and(PharmacyVerificationStatus::Approved->canTransitionTo(PharmacyVerificationStatus::PendingReview))->toBeFalse()
        ->and(PharmacyVerificationStatus::Suspended->canTransitionTo(PharmacyVerificationStatus::PendingReview))->toBeFalse();
});

it('canonicalizes legal registration without inventing a checksum', function () {
    expect(LegalRegistration::canonical('  cr 12-ab  '))->toBe('CR12-AB');

    expect(fn () => LegalRegistration::canonical('   '))->toThrow(InvalidValueObject::class);
    expect(fn () => LegalRegistration::canonical(str_repeat('x', 65)))->toThrow(InvalidValueObject::class);
});

it('rejects illegal and out-of-area coordinates', function () {
    PharmacyCoordinates::assertLegal(30.0, 31.0);
    PharmacyCoordinates::assertEgyptServiceArea(30.0444, 31.2357);

    expect(fn () => PharmacyCoordinates::assertLegal(91.0, 31.0))->toThrow(InvalidValueObject::class);
    expect(fn () => PharmacyCoordinates::assertLegal(30.0, 181.0))->toThrow(InvalidValueObject::class);
    expect(fn () => PharmacyCoordinates::assertEgyptServiceArea(48.8566, 2.3522))->toThrow(InvalidValueObject::class);
});
