<?php

declare(strict_types=1);

use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Platform\Services\Features\PlatformFeatures;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the checked-in FEATURE_IDENTITY_PROFILE_CLAIM default false', function () {
    expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse()
        ->and((bool) config('identity.profile_claim_enabled', true))->toBeFalse()
        ->and((bool) config('identity.profile_claim_enabled'))->toBeFalse();

    $identity = (string) file_get_contents(base_path('config/identity.php'));
    expect($identity)->toContain("env('FEATURE_IDENTITY_PROFILE_CLAIM', false)");
});

it('keeps checked-in env files at FEATURE_IDENTITY_PROFILE_CLAIM=false', function () {
    $root = dirname(base_path(), 2);
    $files = [
        base_path('phpunit.xml'),
        base_path('.env.example'),
        $root.'/infra/environments/local.env',
    ];

    foreach ($files as $path) {
        $contents = (string) file_get_contents($path);
        expect($contents)->toContain('FEATURE_IDENTITY_PROFILE_CLAIM')
            ->and($contents)->not->toMatch('/FEATURE_IDENTITY_PROFILE_CLAIM[^\n]*true/');
    }
});

it('keeps production config true from making PlatformFeatures effective', function () {
    config([
        'identity.profile_claim_enabled' => true,
        'app.env' => 'production',
        'identity.profile_claim.rollout_cohort' => 'everyone',
    ]);
    try {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();
    } finally {
        config([
            'identity.profile_claim_enabled' => false,
            'app.env' => 'testing',
            'identity.profile_claim.rollout_cohort' => '',
        ]);
    }
});

it('cannot bypass PlatformFeatures via rollout_cohort or a true production flag', function () {
    $features = (string) file_get_contents(base_path('Modules/Platform/app/Services/Features/PlatformFeatures.php'));
    $link = (string) file_get_contents(base_path('Modules/Identity/app/Services/LinkVerifiedPatientAccount.php'));
    $otpRequest = (string) file_get_contents(base_path('Modules/Auth/app/Services/RequestOtpService.php'));
    $otpVerify = (string) file_get_contents(base_path('Modules/Auth/app/Services/VerifyOtpService.php'));

    expect($features)
        ->toContain("config('app.env') === 'production'")
        ->toContain('IDENTITY_PROFILE_CLAIM')
        ->and($features)->not->toContain('rollout_cohort')
        ->and($link)->toContain('PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)')
        ->and($link)->not->toContain("config('identity.profile_claim_enabled'")
        ->and($otpRequest)->toContain('PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)')
        ->and($otpVerify)->toContain('PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)');

    $flagPos = strpos($link, 'PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)');
    $attachPos = strpos($link, 'attachAccount');
    expect($flagPos)->toBeInt()->and($attachPos)->toBeInt()->and($flagPos)->toBeLessThan($attachPos);

    config(['identity.profile_claim_enabled' => true, 'app.env' => 'testing', 'identity.profile_claim.rollout_cohort' => 'all']);
    try {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeTrue();
        config(['identity.profile_claim_enabled' => false]);
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();
        config(['identity.profile_claim_enabled' => true, 'app.env' => 'production']);
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();
    } finally {
        config(['identity.profile_claim_enabled' => false, 'app.env' => 'testing', 'identity.profile_claim.rollout_cohort' => '']);
    }
});

it('keeps production clients from exposing a usable claim path', function () {
    $root = dirname(base_path(), 2);
    $patientOnboarding = (string) file_get_contents($root.'/apps/patient-app/lib/onboarding/onboarding_controller.dart');
    $patientScreen = (string) file_get_contents($root.'/apps/patient-app/lib/onboarding/onboarding_screen.dart');
    $features = (string) file_get_contents(base_path('Modules/Platform/app/Services/Features/PlatformFeatures.php'));

    expect($patientOnboarding)->not->toContain('claim_credential')
        ->and($patientOnboarding)->not->toContain('claimCredential')
        ->and($patientScreen)->not->toContain('claim_credential')
        ->and($patientScreen)->not->toContain('claimCredential')
        ->and($features)->toContain("config('app.env') === 'production'")
        ->and(class_exists(LinkVerifiedPatientAccount::class))->toBeTrue();
});
