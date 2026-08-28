<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Services\CredentialIssuer;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{id: string, phone: string, password: string, totp_secret: string, factor_id: string}
 */
function mfaRcInsertDoctor(): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;
    $password = 'correct-horse-battery';
    $factorId = $ids->next()->value;

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Doctor',
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsed)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsed)),
        'phone_key_version' => 1,
        'password_hash' => app(PasswordHasher::class)->hash($password),
        'account_type' => 'doctor',
        'status' => 'active',
        'language' => 'en',
        'credential_version' => 1,
        'phone_verified_at' => $now,
        'last_authenticated_at' => null,
        'bootstrap_exempt' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $totp = app(TotpVerifier::class);
    $secret = $totp->generateSecret();
    DB::table('mfa_factors')->insert([
        'id' => $factorId,
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

    return [
        'id' => $userId,
        'phone' => $phone,
        'password' => $password,
        'totp_secret' => $secret,
        'factor_id' => $factorId,
    ];
}

function mfaRcInsertCode(string $userId, string $factorId): string
{
    $plain = app(CredentialIssuer::class)->recoveryCode();
    DB::table('mfa_recovery_codes')->insert([
        'id' => app(IdentityGenerator::class)->next()->value,
        'user_id' => $userId,
        'factor_id' => $factorId,
        'code_hash' => BinaryColumn::bind(app(CredentialIssuer::class)->hashRecoveryCode($plain)),
        'consumed_at' => null,
        'created_at' => now('UTC'),
    ]);

    return $plain;
}

function mfaRcLoginChallenge(string $phone, string $password): string
{
    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'doctor_desktop',
        'platform' => 'linux',
        'device_label' => 'clinic-pc',
    ]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

    return (string) $login->json('data.challenge_id');
}

function mfaRcUnusedCount(string $userId): int
{
    return (int) DB::table('mfa_recovery_codes')->where('user_id', $userId)->whereNull('consumed_at')->count();
}

function mfaRcDoesNotLeak(mixed $payload, string $plain): void
{
    if (is_object($payload)) {
        $payload = get_object_vars($payload);
    }

    if (! is_array($payload)) {
        expect((string) $payload)->not->toContain($plain);

        return;
    }

    $safe = [];
    foreach ($payload as $key => $value) {
        if (is_resource($value)) {
            continue;
        }
        $safe[$key] = $value;
    }

    $encoded = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    expect($encoded)->toBeString()->not->toContain($plain);
}

function mfaRcStoredHashIsNotPlaintext(string $userId, string $plain): void
{
    $hash = BinaryColumn::asString(DB::table('mfa_recovery_codes')->where('user_id', $userId)->value('code_hash'));
    expect($hash)->not->toBe($plain)
        ->and($hash)->not->toBe(strtoupper($plain))
        ->and($hash)->not->toBe('');
}

describe('MFA recovery-code complete HTTP matrix', function () {
    it('returns 200 and aal2_totp when an unused recovery code completes the challenge once', function () {
        $doctor = mfaRcInsertDoctor();
        $plain = mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $challengeId = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);

        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'recovery_code' => $plain,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.session_kind', 'device')
            ->assertJsonPath('data.assurance_level', 'aal2_totp');
        expect($response->json('data.access_token'))->toBeString()->not->toBe('')
            ->and(mfaRcUnusedCount($doctor['id']))->toBe(0)
            ->and(DB::table('mfa_challenges')->where('id', $challengeId)->value('consumed_at'))->not->toBeNull()
            ->and((int) DB::table('users')->where('id', $doctor['id'])->value('credential_version'))->toBe(1)
            ->and((string) DB::table('auth_sessions')->where('user_id', $doctor['id'])->value('assurance_level'))->toBe('aal2_totp')
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(1);
        mfaRcDoesNotLeak($response->json(), $plain);
        mfaRcDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->first(), $plain);
        mfaRcStoredHashIsNotPlaintext($doctor['id'], $plain);
    });

    it('returns 422 when the same recovery code is replayed on the same challenge', function () {
        $doctor = mfaRcInsertDoctor();
        $plain = mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $challengeId = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);

        $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'recovery_code' => $plain,
        ])->assertOk();
        $consumedAt = (string) DB::table('mfa_recovery_codes')->where('user_id', $doctor['id'])->value('consumed_at');
        $sessions = (int) DB::table('auth_sessions')->where('user_id', $doctor['id'])->count();

        $replay = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'recovery_code' => $plain,
        ]);

        $replay->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect((string) DB::table('mfa_recovery_codes')->where('user_id', $doctor['id'])->value('consumed_at'))->toBe($consumedAt)
            ->and((int) DB::table('auth_sessions')->where('user_id', $doctor['id'])->count())->toBe($sessions)
            ->and((int) DB::table('users')->where('id', $doctor['id'])->value('credential_version'))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(1);
        mfaRcDoesNotLeak($replay->json(), $plain);
    });

    it('returns 422 when the recovery code is invalid and does not consume a row', function () {
        $doctor = mfaRcInsertDoctor();
        mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $challengeId = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);

        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'recovery_code' => 'DEADBEEF01',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(mfaRcUnusedCount($doctor['id']))->toBe(1)
            ->and(DB::table('mfa_challenges')->where('id', $challengeId)->value('consumed_at'))->toBeNull()
            ->and(DB::table('auth_sessions')->where('user_id', $doctor['id'])->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(0);
        mfaRcDoesNotLeak($response->json(), 'DEADBEEF01');
    });

    it('returns 422 when the recovery code belongs to another user', function () {
        $owner = mfaRcInsertDoctor();
        $plain = mfaRcInsertCode($owner['id'], $owner['factor_id']);
        $other = mfaRcInsertDoctor();
        mfaRcInsertCode($other['id'], $other['factor_id']);
        $challengeId = mfaRcLoginChallenge($other['phone'], $other['password']);

        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'recovery_code' => $plain,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(mfaRcUnusedCount($owner['id']))->toBe(1)
            ->and(mfaRcUnusedCount($other['id']))->toBe(1)
            ->and(DB::table('mfa_challenges')->where('id', $challengeId)->value('consumed_at'))->toBeNull()
            ->and(DB::table('auth_sessions')->where('user_id', $other['id'])->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(0);
        mfaRcDoesNotLeak($response->json(), $plain);
    });

    it('returns 422 when a consumed recovery code is presented on a new challenge', function () {
        $doctor = mfaRcInsertDoctor();
        $plain = mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $first = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);
        $this->postJson('/api/v1/auth/mfa/challenges/'.$first.'/verify', [
            'recovery_code' => $plain,
        ])->assertOk();
        $consumedAt = (string) DB::table('mfa_recovery_codes')->where('user_id', $doctor['id'])->value('consumed_at');
        $sessions = (int) DB::table('auth_sessions')->where('user_id', $doctor['id'])->count();

        $second = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);
        $replay = $this->postJson('/api/v1/auth/mfa/challenges/'.$second.'/verify', [
            'recovery_code' => $plain,
        ]);

        $replay->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect((string) DB::table('mfa_recovery_codes')->where('user_id', $doctor['id'])->value('consumed_at'))->toBe($consumedAt)
            ->and(DB::table('mfa_challenges')->where('id', $second)->value('consumed_at'))->toBeNull()
            ->and((int) DB::table('auth_sessions')->where('user_id', $doctor['id'])->count())->toBe($sessions)
            ->and((int) DB::table('users')->where('id', $doctor['id'])->value('credential_version'))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(1);
        mfaRcDoesNotLeak($replay->json(), $plain);
    });

    it('returns 422 when a valid recovery code is used against an expired MFA challenge', function () {
        $doctor = mfaRcInsertDoctor();
        $plain = mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $challengeId = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);
        DB::table('mfa_challenges')->where('id', $challengeId)->update([
            'expires_at' => now('UTC')->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'recovery_code' => $plain,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(mfaRcUnusedCount($doctor['id']))->toBe(1)
            ->and(DB::table('mfa_challenges')->where('id', $challengeId)->value('consumed_at'))->toBeNull()
            ->and(DB::table('auth_sessions')->where('user_id', $doctor['id'])->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(0);
        mfaRcDoesNotLeak($response->json(), $plain);
    });

    it('returns 200 when a normal TOTP code still completes MFA', function () {
        $doctor = mfaRcInsertDoctor();
        mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $challengeId = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);
        $code = app(TotpVerifier::class)->codeAt($doctor['totp_secret'], app(Clock::class)->now());

        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.session_kind', 'device')
            ->assertJsonPath('data.assurance_level', 'aal2_totp');
        expect(mfaRcUnusedCount($doctor['id']))->toBe(1)
            ->and((string) DB::table('auth_sessions')->where('user_id', $doctor['id'])->value('assurance_level'))->toBe('aal2_totp')
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(0);
    });

    it('returns 422 when the request contains both a TOTP code and a recovery code', function () {
        $doctor = mfaRcInsertDoctor();
        $plain = mfaRcInsertCode($doctor['id'], $doctor['factor_id']);
        $challengeId = mfaRcLoginChallenge($doctor['phone'], $doctor['password']);
        $code = app(TotpVerifier::class)->codeAt($doctor['totp_secret'], app(Clock::class)->now());

        $response = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'code' => $code,
            'recovery_code' => $plain,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(mfaRcUnusedCount($doctor['id']))->toBe(1)
            ->and(DB::table('mfa_challenges')->where('id', $challengeId)->value('consumed_at'))->toBeNull()
            ->and(DB::table('auth_sessions')->where('user_id', $doctor['id'])->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_recovery_code_consumed')->count())->toBe(0);
        mfaRcDoesNotLeak($response->json(), $plain);
    });
});
