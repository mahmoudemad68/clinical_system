<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('admin cookie session HTTP', function () {
    it('authenticates GET and POST from the cookie HMAC when login_web is absent', function () {
        $pending = adminVerificationPendingCase('hmac-only');
        $admin = adminVerificationInsertAdmin('hmac-only');
        adminVerificationLogin($admin);

        session()->forget(Auth::guard('web')->getName());
        session()->save();
        Auth::guard('web')->forgetUser();

        adminVerificationGetJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.account_type', 'admin');

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertOk();
    });

    it('rebinds the cookie HMAC after Laravel rotates the session id', function () {
        $admin = adminVerificationInsertAdmin('hmac-rebind');
        adminVerificationLogin($admin);
        adminVerificationGetJson('/api/v1/me')->assertOk();

        session()->migrate(true);
        session()->save();
        Auth::guard('web')->forgetUser();

        adminVerificationGetJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.account_type', 'admin');
    });

    it('accepts the encrypted X-XSRF-TOKEN header without a body _token', function () {
        $admin = adminVerificationInsertAdmin('xsrf-header');
        adminVerificationLogin($admin);

        $csrf = adminVerificationGetJson('/api/v1/auth/csrf')->assertOk();
        $xsrf = collect($csrf->headers->getCookies())->first(
            static fn ($cookie): bool => $cookie->getName() === 'XSRF-TOKEN',
        );
        expect($xsrf)->not->toBeNull();

        Auth::guard('web')->forgetUser();
        $response = test()->withCredentials()
            ->withCookie((string) config('session.cookie'), (string) session()->getId())
            ->postJson('/api/v1/auth/logout', [], [
                'X-XSRF-TOKEN' => (string) $xsrf?->getValue(),
            ]);

        $response->assertOk()->assertJsonPath('data.revoked', true);
    });
});
