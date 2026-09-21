<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Modules\Access\Support\Capabilities;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('refuses the browser verification fixture seeder outside local/testing', function () {
    config(['app.env' => 'production']);

    $result = Artisan::call('e2e:seed-admin-verification', ['--write' => '/tmp/clinic-e2e-admin-verification.json']);

    expect($result)->toBe(1)
        ->and(Artisan::output())->toContain('disabled outside local/testing');
});

it('refuses to write the browser fixture outside /tmp', function () {
    config(['app.env' => 'testing']);

    $result = Artisan::call('e2e:seed-admin-verification', ['--write' => '/var/clinic-e2e.json']);

    expect($result)->toBe(1)
        ->and(Artisan::output())->toContain('/tmp/');
});

it('seeds a secretary unauthorized actor who can read me but not the review queue', function () {
    $path = '/tmp/clinic-e2e-admin-verification-pest.json';
    @unlink($path);

    $result = Artisan::call('e2e:seed-admin-verification', ['--write' => $path]);
    expect($result)->toBe(0)->and(is_file($path))->toBeTrue();

    $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($fixture['case']['professional_display_name'])->toBe('Dr E2E Review')
        ->and($fixture['pharmacy_case']['public_name'] ?? null)->toBe('E2E Pharmacy Review')
        ->and(is_string($fixture['unauthorized']['phone'] ?? null))->toBeTrue()
        ->and(is_string($fixture['unauthorized']['password'] ?? null))->toBeTrue();

    $this->withCredentials();
    $this->getJson('/api/v1/auth/csrf')->assertOk();
    $csrf = csrf_token();
    $login = $this->postJson('/api/v1/auth/login', [
        'phone' => $fixture['unauthorized']['phone'],
        'password' => $fixture['unauthorized']['password'],
        'client_class' => 'admin_web',
        'platform' => 'web',
        'device_label' => 'admin-browser',
        '_token' => $csrf,
    ], ['X-CSRF-TOKEN' => $csrf]);

    $login->assertOk()->assertJsonPath('data.session_kind', 'admin_cookie');
    expect($login->json('data.capabilities'))->not->toContain(Capabilities::VERIFICATION_REVIEW);

    Auth::guard('web')->forgetUser();
    adminVerificationPinCookie();

    adminVerificationGetJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.account_type', 'secretary');
    $caps = adminVerificationGetJson('/api/v1/me/capabilities')->assertOk();
    expect($caps->json('data.capabilities'))->not->toContain(Capabilities::VERIFICATION_REVIEW);
    adminVerificationGetJson('/api/v1/admin/verification-cases')->assertNotFound();

    @unlink($path);
});
