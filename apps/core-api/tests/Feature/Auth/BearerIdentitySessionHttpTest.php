<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $response->assertOk();

        $names = collect($response->headers->getCookies())
            ->map(static fn ($cookie): string => $cookie->getName())
            ->all();

        expect($names)->toContain('XSRF-TOKEN');
    });
});
