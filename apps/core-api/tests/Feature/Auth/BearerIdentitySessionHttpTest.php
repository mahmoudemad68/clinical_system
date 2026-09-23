<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return list<string>
 */
function bearerIdentitySessionCookieNames($response): array
{
    $session = (string) config('session.cookie');

    return collect($response->headers->getCookies())
        ->map(static fn ($cookie): string => $cookie->getName())
        ->filter(static fn (string $name): bool => $name === $session || str_starts_with($name, 'laravel_session'))
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function bearerIdentityCookieHeaderNames($response): array
{
    return collect($response->headers->getCookies())
        ->map(static fn ($cookie): string => $cookie->getName())
        ->values()
        ->all();
}

describe('bearer identity session HTTP', function () {
    it('reads the own patient profile without a session cookie and with one users lookup', function () {
        $session = patientsActiveSession('perf-me');
        $canary = $session['payload']['national_id'];
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($canary, 'Perf Patient'),
            patientsAuth($session['token']) + patientsIdem('pon-perf-me'),
        )->assertCreated();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $me = $this->getJson('/api/v1/patients/me/profile', patientsAuth($session['token']));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = array_map(static fn (array $query): string => (string) $query['query'], $queries);
        $userLookups = array_values(array_filter(
            $sql,
            static fn (string $query): bool => str_contains(strtolower($query), 'from "users"')
                || str_contains(strtolower($query), 'from users'),
        ));

        $me->assertOk()
            ->assertJsonPath('data.full_name', 'Perf Patient')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.national_id_ciphertext')
            ->assertJsonMissingPath('data.phone');

        expect($me->getContent())->not->toContain($canary)
            ->and(bearerIdentitySessionCookieNames($me))->toBe([])
            ->and($userLookups)->toHaveCount(1)
            ->and($sql)->toHaveCount(4)
            ->and($sql)->not->toContain('select * from "sessions"');
    });

    it('returns 401 when the patient profile is requested without a bearer token', function () {
        $response = $this->getJson('/api/v1/patients/me/profile');

        $response->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
    });

    it('returns 401 when the patient profile is requested with an invalid bearer token', function () {
        $response = $this->getJson('/api/v1/patients/me/profile', patientsAuth('not-a-device-token'));

        $response->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');

        expect(bearerIdentitySessionCookieNames($response))->toBe([]);
    });

    it('does not disclose another patient profile to a bearer caller', function () {
        $owner = patientsActiveSession('perf-owner');
        $created = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($owner['payload']['national_id'], 'Owner Patient'),
            patientsAuth($owner['token']) + patientsIdem('pon-perf-owner'),
        )->assertCreated();

        $other = patientsActiveSession('perf-other');
        $this->getJson('/api/v1/patients/me/profile', patientsAuth($other['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/patients/'.$created->json('data.patient_id'), patientsAuth($other['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/admin/verification-cases', patientsAuth($other['token']))
            ->assertNotFound();
    });

    it('revokes the device session on bearer logout without requiring a laravel session store', function () {
        $session = patientsActiveSession('perf-logout');

        $this->postJson('/api/v1/auth/logout', [], patientsAuth($session['token']))
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->getJson('/api/v1/patients/me/profile', patientsAuth($session['token']))
            ->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
    });

    it('still issues the csrf cookie when no bearer token is present', function () {
        $response = $this->getJson('/api/v1/auth/csrf');

        $response->assertOk()
            ->assertJsonPath('data.csrf', true);

        expect(bearerIdentityCookieHeaderNames($response))->toContain('XSRF-TOKEN');
    });

    it('rejects a valid bearer on csrf with MALFORMED_REQUEST and no session cookie', function () {
        $session = patientsActiveSession('csrf-valid-bearer');

        $response = $this->getJson('/api/v1/auth/csrf', patientsAuth($session['token']));

        $response->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'MALFORMED_REQUEST');

        expect(bearerIdentitySessionCookieNames($response))->toBe([])
            ->and(bearerIdentityCookieHeaderNames($response))->not->toContain('XSRF-TOKEN');
    });

    it('rejects an invalid bearer on csrf with MALFORMED_REQUEST rather than 500', function () {
        $response = $this->getJson('/api/v1/auth/csrf', patientsAuth('not-a-device-token'));

        $response->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'MALFORMED_REQUEST');

        expect(bearerIdentitySessionCookieNames($response))->toBe([])
            ->and(bearerIdentityCookieHeaderNames($response))->not->toContain('XSRF-TOKEN');
    });

    it('does not fall back to a browser cookie identity when an invalid bearer is also supplied', function () {
        $admin = adminVerificationInsertAdmin('csrf-xor-bearer');
        adminVerificationLogin($admin);
        adminVerificationPinCookie();

        $csrf = $this->getJson('/api/v1/auth/csrf', patientsAuth('not-a-device-token'));
        $csrf->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'MALFORMED_REQUEST');
        expect(bearerIdentityCookieHeaderNames($csrf))->not->toContain('XSRF-TOKEN');

        $me = $this->getJson('/api/v1/me', patientsAuth('not-a-device-token'));
        $me->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED')
            ->assertJsonMissingPath('data.account_type');
    });

    it('still rejects an unsafe cookie request without a valid csrf token', function () {
        $admin = adminVerificationInsertAdmin('csrf-mismatch-logout');
        adminVerificationLogin($admin);
        Auth::guard('web')->forgetUser();

        $this->withCredentials()
            ->withCookie((string) config('session.cookie'), (string) session()->getId())
            ->postJson('/api/v1/auth/logout', [])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'CSRF_MISMATCH');
    });

    it('does not write a redis session key for an ordinary bearer profile request', function () {
        try {
            Redis::connection()->ping();
        } catch (Throwable) {
            test()->markTestSkipped('Redis is not reachable.');
        }

        $session = patientsActiveSession('csrf-redis-bearer');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id'], 'Redis Patient'),
            patientsAuth($session['token']) + patientsIdem('pon-redis-bearer'),
        )->assertCreated();

        $previousDriver = (string) config('session.driver');
        config(['session.driver' => 'redis']);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');

        try {
            $before = collect(Redis::connection()->keys('*'))->sort()->values()->all();

            $me = $this->getJson('/api/v1/patients/me/profile', patientsAuth($session['token']));
            $me->assertOk();
            expect(bearerIdentitySessionCookieNames($me))->toBe([]);

            $after = collect(Redis::connection()->keys('*'))->sort()->values()->all();
            expect($after)->toBe($before);
        } finally {
            config(['session.driver' => $previousDriver]);
            $this->app->forgetInstance('session');
            $this->app->forgetInstance('session.store');
        }
    });
});
