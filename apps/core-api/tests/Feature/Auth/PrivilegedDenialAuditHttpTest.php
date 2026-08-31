<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    cache()->store((string) config('cache.auth_rate_limiter', 'ratelimit'))->flush();
    app()->forgetInstance(AuthenticationRateLimiter::class);
});

/**
 * @return array{id: string, phone: string, password: string, totp_secret: ?string}
 */
function privilegedDenialInsertUser(string $accountType, bool $withTotp = true): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;
    $password = 'correct-horse-battery';

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic '.$accountType,
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsed)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsed)),
        'phone_key_version' => 1,
        'password_hash' => app(PasswordHasher::class)->hash($password),
        'account_type' => $accountType,
        'status' => 'active',
        'language' => 'en',
        'credential_version' => 1,
        'phone_verified_at' => $now,
        'last_authenticated_at' => null,
        'bootstrap_exempt' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $secret = null;
    if ($withTotp) {
        $totp = app(TotpVerifier::class);
        $secret = $totp->generateSecret();
        DB::table('mfa_factors')->insert([
            'id' => $ids->next()->value,
            'user_id' => $userId,
            'factor_type' => 'totp',
            'secret_ciphertext' => BinaryColumn::bind($protector->encryptSecret('mfa_secret', $secret)),
            'key_version' => 1,
            'last_used_counter' => null,
            'last_used_at' => null,
            'verified_at' => $now,
            'disabled_at' => null,
            'disabled_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return [
        'id' => $userId,
        'phone' => $phone,
        'password' => $password,
        'totp_secret' => $secret,
    ];
}

function privilegedDenialLoginPayload(string $phone, string $password, string $clientClass, string $platform): array
{
    return [
        'phone' => $phone,
        'password' => $password,
        'client_class' => $clientClass,
        'platform' => $platform,
        'device_label' => 'clinic-pc',
    ];
}

/**
 * @return array<string, mixed>
 */
function privilegedDenialMetadata(object $row): array
{
    $raw = $row->metadata;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $metadata = is_array($decoded) ? $decoded : [];
    } elseif (is_array($raw)) {
        $metadata = $raw;
    } elseif (is_object($raw)) {
        $metadata = get_object_vars($raw);
    } else {
        $metadata = [];
    }

    ksort($metadata);

    return $metadata;
}

function privilegedDenialAuthFailedCount(?string $actorId = null): int
{
    $query = DB::table('audit_events')->where('event_name', 'auth.privileged_authentication_failed');
    if ($actorId !== null) {
        $query->where('actor_id', $actorId);
    }

    return (int) $query->count();
}

function privilegedDenialDoesNotContain(object $row, string $secret): void
{
    $encoded = json_encode(privilegedDenialMetadata($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($encoded)->toBeString()->not->toContain($secret);
}

describe('privileged authentication failure audit', function () {
    it('returns 401 and appends a durable redacted row when privileged password authentication fails', function () {
        $doctor = privilegedDenialInsertUser('doctor');
        $wrongPassword = 'wrong-privileged-secret-9f3a';

        $response = $this->postJson('/api/v1/auth/login', privilegedDenialLoginPayload(
            $doctor['phone'],
            $wrongPassword,
            'doctor_desktop',
            'linux',
        ));

        $response->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
        expect(privilegedDenialAuthFailedCount($doctor['id']))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.session_issued')->where('actor_id', $doctor['id'])->count())->toBe(0);

        $row = DB::table('audit_events')->where('event_name', 'auth.privileged_authentication_failed')->where('actor_id', $doctor['id'])->first();
        expect($row)->not->toBeNull()
            ->and((string) $row->object_type)->toBe('user')
            ->and((string) $row->object_id)->toBe($doctor['id']);
        expect(privilegedDenialMetadata($row))->toBe([
            'account_type' => 'doctor',
            'method' => 'password',
            'reason_code' => 'invalid_credentials',
        ]);
        privilegedDenialDoesNotContain($row, $wrongPassword);
        privilegedDenialDoesNotContain($row, $doctor['password']);
    });

    it('returns 422 and appends a durable redacted row when privileged MFA verification fails', function () {
        $now = new DateTimeImmutable('2026-08-30 12:00:00', new DateTimeZone('UTC'));
        app()->instance(Clock::class, new FrozenClock($now));
        $doctor = privilegedDenialInsertUser('doctor');
        $login = $this->postJson('/api/v1/auth/login', privilegedDenialLoginPayload(
            $doctor['phone'],
            $doctor['password'],
            'doctor_desktop',
            'linux',
        ));
        $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

        $valid = app(TotpVerifier::class)->codeAt((string) $doctor['totp_secret'], $now);
        $wrongCode = $valid === '000000' ? '111111' : '000000';
        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
            'code' => $wrongCode,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(privilegedDenialAuthFailedCount($doctor['id']))->toBe(1);

        $row = DB::table('audit_events')->where('event_name', 'auth.privileged_authentication_failed')->where('actor_id', $doctor['id'])->first();
        expect($row)->not->toBeNull();
        expect(privilegedDenialMetadata($row))->toBe([
            'account_type' => 'doctor',
            'method' => 'totp',
            'reason_code' => 'totp_invalid',
        ]);
        privilegedDenialDoesNotContain($row, $wrongCode);
        privilegedDenialDoesNotContain($row, (string) $doctor['totp_secret']);
        privilegedDenialDoesNotContain($row, $doctor['password']);
    });

    it('still emits session issuance and does not append a privileged failure after a successful privileged MFA', function () {
        $now = new DateTimeImmutable('2026-08-30 12:05:00', new DateTimeZone('UTC'));
        app()->instance(Clock::class, new FrozenClock($now));
        $doctor = privilegedDenialInsertUser('doctor');
        $login = $this->postJson('/api/v1/auth/login', privilegedDenialLoginPayload(
            $doctor['phone'],
            $doctor['password'],
            'doctor_desktop',
            'linux',
        ));
        $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

        $ok = $this->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
            'code' => app(TotpVerifier::class)->codeAt((string) $doctor['totp_secret'], $now),
        ]);

        $ok->assertOk()->assertJsonPath('data.session_kind', 'device');
        expect(privilegedDenialAuthFailedCount($doctor['id']))->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.session_issued')->where('actor_id', $doctor['id'])->count())->toBe(1);
    });

    it('returns the same 401 envelope for unknown, patient, and privileged password failures without a privileged event on non-privileged attempts', function () {
        $unknownPhone = (new SyntheticEgyptianData)->mobileNumber();
        $unknownPassword = 'unknown-account-secret-7c1b';
        $unknown = $this->postJson('/api/v1/auth/login', privilegedDenialLoginPayload(
            $unknownPhone,
            $unknownPassword,
            'patient_mobile',
            'android',
        ));

        $patient = privilegedDenialInsertUser('patient', false);
        $patientWrong = 'patient-wrong-secret-4d2e';
        $patientDenied = $this->postJson('/api/v1/auth/login', privilegedDenialLoginPayload(
            $patient['phone'],
            $patientWrong,
            'patient_mobile',
            'android',
        ));

        $doctor = privilegedDenialInsertUser('doctor');
        $privilegedWrong = 'privileged-wrong-secret-8a0c';
        $privilegedDenied = $this->postJson('/api/v1/auth/login', privilegedDenialLoginPayload(
            $doctor['phone'],
            $privilegedWrong,
            'doctor_desktop',
            'linux',
        ));

        $unknown->assertUnauthorized();
        $patientDenied->assertUnauthorized();
        $privilegedDenied->assertUnauthorized();
        expect($unknown->json('errors.0.code'))->toBe('UNAUTHENTICATED')
            ->and($patientDenied->json('errors.0.code'))->toBe('UNAUTHENTICATED')
            ->and($privilegedDenied->json('errors.0.code'))->toBe('UNAUTHENTICATED')
            ->and($unknown->json('errors.0.message'))->toBe($patientDenied->json('errors.0.message'))
            ->and($patientDenied->json('errors.0.message'))->toBe($privilegedDenied->json('errors.0.message'))
            ->and(privilegedDenialAuthFailedCount($patient['id']))->toBe(0)
            ->and(privilegedDenialAuthFailedCount($doctor['id']))->toBe(1);

        $row = DB::table('audit_events')->where('event_name', 'auth.privileged_authentication_failed')->first();
        privilegedDenialDoesNotContain($row, $unknownPassword);
        privilegedDenialDoesNotContain($row, $patientWrong);
        privilegedDenialDoesNotContain($row, $privilegedWrong);
        privilegedDenialDoesNotContain($row, $unknownPhone);
    });

    it('keeps existing login rate limits and does not append a privileged failure for a 429', function () {
        config([
            'identity.rate_limits.login_per_ip_per_minute' => 2,
            'identity.rate_limits.login_per_subject_per_minute' => 100,
        ]);
        app()->forgetInstance(AuthenticationRateLimiter::class);

        $doctor = privilegedDenialInsertUser('doctor');
        $payload = privilegedDenialLoginPayload(
            $doctor['phone'],
            'rate-limited-secret-2b9d',
            'doctor_desktop',
            'linux',
        );

        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        $limited = $this->postJson('/api/v1/auth/login', $payload);

        $limited->assertTooManyRequests()->assertHeader('Retry-After');
        expect(privilegedDenialAuthFailedCount($doctor['id']))->toBe(2);
    });
});
