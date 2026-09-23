<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Services\AuthenticatePasswordService;
use Modules\Auth\Services\Crypto\Argon2idPasswordHasher;
use Modules\Doctors\Services\CreateAdminDoctorApplicant;
use Modules\Platform\Exceptions\AuthenticationFailed;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Verification\Services\VerificationService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    app()->forgetInstance(PasswordHasher::class);
});

it('primes before the known-unknown branch so an unknown phone does not pay an extra make', function () {
    $hasher = app(PasswordHasher::class);
    expect($hasher)->toBeInstanceOf(Argon2idPasswordHasher::class)
        ->and($hasher->unknownUserDummyIsPrimed())->toBeFalse();

    $phone = (new SyntheticEgyptianData)->mobileNumber();

    expect(fn () => app(AuthenticatePasswordService::class)->handle(
        $phone,
        'definitely-not-the-password',
        'patient_mobile',
        'android',
        'phone',
        '127.0.0.0',
    ))->toThrow(AuthenticationFailed::class);

    expect($hasher->unknownUserDummyIsPrimed())->toBeTrue();
});

it('does not prime the dummy merely by resolving verification or admin provisioning', function () {
    $hasher = app(PasswordHasher::class);
    expect($hasher->unknownUserDummyIsPrimed())->toBeFalse();

    app(CreateAdminDoctorApplicant::class);
    app(VerificationService::class);

    expect($hasher->unknownUserDummyIsPrimed())->toBeFalse();
});
