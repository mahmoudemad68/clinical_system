<?php

declare(strict_types=1);

use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Clinics\Support\ClinicCoordinates;
use Modules\Platform\Exceptions\InvalidValueObject;
use Tests\TestCase;

uses(TestCase::class);

it('treats active as location readiness only', function () {
    expect(ClinicLocationStatus::Active->isLocationReady())->toBeTrue()
        ->and(ClinicLocationStatus::Draft->isLocationReady())->toBeFalse()
        ->and(ClinicLocationStatus::Pending->isLocationReady())->toBeFalse()
        ->and(ClinicLocationStatus::Suspended->isLocationReady())->toBeFalse()
        ->and(ClinicLocationStatus::Closed->isLocationReady())->toBeFalse();
});

it('reserves doctor membership role and invites secretaries only', function () {
    expect(ClinicStaffRole::Secretary->isInviteableInChunk10())->toBeTrue()
        ->and(ClinicStaffRole::Doctor->isInviteableInChunk10())->toBeFalse();
});

it('rejects illegal and out-of-area coordinates', function () {
    ClinicCoordinates::assertLegal(30.0, 31.0);
    ClinicCoordinates::assertEgyptServiceArea(30.0444, 31.2357);

    expect(fn () => ClinicCoordinates::assertLegal(91.0, 31.0))->toThrow(InvalidValueObject::class);
    expect(fn () => ClinicCoordinates::assertLegal(30.0, 181.0))->toThrow(InvalidValueObject::class);
    expect(fn () => ClinicCoordinates::assertEgyptServiceArea(48.8566, 2.3522))->toThrow(InvalidValueObject::class);
});
