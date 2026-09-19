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

it('keeps public listing as a closed vocabulary', function () {
    expect(DoctorPublicStatus::values())->toBe(['hidden', 'listed']);
});

it('trims syndicate identifiers without inventing a checksum', function () {
    expect(SyndicateNumber::canonical(null))->toBeNull()
        ->and(SyndicateNumber::canonical('  ABC-12  '))->toBe('ABC-12');

    expect(fn () => SyndicateNumber::canonical('   '))->toThrow(InvalidValueObject::class);
    expect(fn () => SyndicateNumber::canonical(str_repeat('x', 65)))->toThrow(InvalidValueObject::class);
});
