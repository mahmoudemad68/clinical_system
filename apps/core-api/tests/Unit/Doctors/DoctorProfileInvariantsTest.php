<?php

declare(strict_types=1);

use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Support\SyndicateNumber;
use Modules\Platform\Exceptions\InvalidValueObject;
use Tests\TestCase;

uses(TestCase::class);

it('never treats verification status as a clinical grant', function () {
    foreach (DoctorVerificationStatus::cases() as $status) {
        expect($status->confersClinicalCapability())->toBeFalse();
    }
});

it('allows a new case only from draft, changes requested, or rejected', function () {
    expect(DoctorVerificationStatus::Draft->allowsNewVerificationCase())->toBeTrue()
        ->and(DoctorVerificationStatus::ChangesRequested->allowsNewVerificationCase())->toBeTrue()
        ->and(DoctorVerificationStatus::Rejected->allowsNewVerificationCase())->toBeTrue()
        ->and(DoctorVerificationStatus::PendingReview->allowsNewVerificationCase())->toBeFalse()
        ->and(DoctorVerificationStatus::Approved->allowsNewVerificationCase())->toBeFalse()
        ->and(DoctorVerificationStatus::Suspended->allowsNewVerificationCase())->toBeFalse();
});

it('rejects invalid professional verification transitions', function () {
    expect(DoctorVerificationStatus::Draft->canTransitionTo(DoctorVerificationStatus::Approved))->toBeFalse()
        ->and(DoctorVerificationStatus::Approved->canTransitionTo(DoctorVerificationStatus::PendingReview))->toBeFalse();
});

it('keeps public listing as a closed vocabulary', function () {
    expect(DoctorPublicStatus::values())->toBe(['hidden', 'listed']);
});

it('trims syndicate identifiers without inventing a checksum', function () {
    expect(SyndicateNumber::canonical(null))->toBeNull()
        ->and(SyndicateNumber::canonical('  ABC-12  '))->toBe('ABC-12');

    expect(fn () => SyndicateNumber::canonical('   '))->toThrow(InvalidValueObject::class);
    expect(fn () => SyndicateNumber::canonical(str_repeat('x', 65)))->toThrow(InvalidValueObject::class);
});
