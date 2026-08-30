<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Services\CredentialIssuer;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{id: string, phone: string, password: string, totp_secret: string, factor_id: string, account_type: string}
 */
function lostTotpInsertUser(string $accountType = 'doctor'): array
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
        'name' => $accountType === 'patient' ? 'Synthetic Patient' : 'Synthetic Doctor',
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

    $secret = app(TotpVerifier::class)->generateSecret();
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
        'account_type' => $accountType,
    ];
}

/**
 * @return array{id: string, phone: string, password: string, account_type: string}
 */
function lostTotpInsertPasswordOnly(string $accountType = 'patient'): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $now = now('UTC');
    $userId = app(IdentityGenerator::class)->next()->value;
    $password = 'correct-horse-battery';

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Password User',
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

    return [
        'id' => $userId,
        'phone' => $phone,
        'password' => $password,
        'account_type' => $accountType,
    ];
}

function lostTotpInsertCode(string $userId, string $factorId): string
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

function lostTotpDoctorChallenge(string $phone, string $password): string
{
    Auth::guard('web')->forgetUser();
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

/**
 * @return array{token: string, session_id: string}
 */
function lostTotpCompleteRecovery(string $challengeId, string $plain): array
{
    $response = test()->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
        'recovery_code' => $plain,
    ]);
    $response->assertOk()
        ->assertJsonPath('data.assurance_level', 'aal2_recovery_code');

    return [
        'token' => (string) $response->json('data.access_token'),
        'session_id' => (string) $response->json('data.session_id'),
    ];
}

function lostTotpCompleteTotp(string $challengeId, string $secret): string
{
    $code = app(TotpVerifier::class)->codeAt($secret, app(Clock::class)->now());
    $response = test()->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
        'code' => $code,
    ]);
    $response->assertOk()->assertJsonPath('data.assurance_level', 'aal2_totp');

    return (string) $response->json('data.access_token');
}

/**
 * @return array<string, string>
 */
function lostTotpBearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

function lostTotpSecretFromUri(string $uri): string
{
    $query = [];
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

    return (string) ($query['secret'] ?? '');
}

function lostTotpDoesNotLeak(mixed $payload, string $needle): void
{
    if ($needle === '') {
        return;
    }

    if (is_object($payload)) {
        $payload = get_object_vars($payload);
    }

    if (! is_array($payload)) {
        expect((string) $payload)->not->toContain($needle);

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
    expect($encoded)->toBeString()->not->toContain($needle);
}

function lostTotpActiveId(string $userId): ?string
{
    $id = DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->whereNotNull('verified_at')
        ->value('id');

    return is_string($id) ? $id : null;
}

function lostTotpPendingId(string $userId): ?string
{
    $id = DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->whereNull('verified_at')
        ->value('id');

    return is_string($id) ? $id : null;
}

function lostTotpVerifiedCount(string $userId): int
{
    return (int) DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->whereNotNull('verified_at')
        ->count();
}

function lostTotpUnusedCodes(string $userId): int
{
    return (int) DB::table('mfa_recovery_codes')->where('user_id', $userId)->whereNull('consumed_at')->count();
}

describe('lost TOTP re-enrollment HTTP matrix', function () {
    it('returns 422 when an aal2_totp session tries to replace an active factor', function () {
        $doctor = lostTotpInsertUser();
        lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $token = lostTotpCompleteTotp(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $doctor['totp_secret']);

        $response = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($token));

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect($response->json('errors.0.message'))->toBe('An authenticator factor is already pending or active.')
            ->and(lostTotpActiveId($doctor['id']))->toBe($doctor['factor_id'])
            ->and(lostTotpPendingId($doctor['id']))->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_started')->where('actor_id', $doctor['id'])->count())->toBe(0);
    });

    it('returns 422 when an aal1_password session tries to replace an active factor', function () {
        $patient = lostTotpInsertUser('patient');
        lostTotpInsertCode($patient['id'], $patient['factor_id']);
        $login = $this->postJson('/api/v1/auth/login', [
            'phone' => $patient['phone'],
            'password' => $patient['password'],
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ]);
        $login->assertOk()->assertJsonPath('data.assurance_level', 'aal1_password');
        $token = (string) $login->json('data.access_token');

        $response = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($token));

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(lostTotpActiveId($patient['id']))->toBe($patient['factor_id'])
            ->and(lostTotpPendingId($patient['id']))->toBeNull();
    });

    it('returns 200 and keeps the old factor active when a recovery-code session starts replacement', function () {
        $doctor = lostTotpInsertUser();
        $plain = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $session = lostTotpCompleteRecovery(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $plain);

        $response = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']));

        $response->assertOk();
        $uri = (string) $response->json('data.provisioning_uri');
        $pendingId = (string) $response->json('data.factor_id');
        expect($uri)->toStartWith('otpauth://')
            ->and($pendingId)->not->toBe($doctor['factor_id'])
            ->and(lostTotpActiveId($doctor['id']))->toBe($doctor['factor_id'])
            ->and(lostTotpPendingId($doctor['id']))->toBe($pendingId)
            ->and(lostTotpVerifiedCount($doctor['id']))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_started')->where('actor_id', $doctor['id'])->count())->toBe(1);
        lostTotpDoesNotLeak($response->json(), $plain);
        lostTotpDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_started')->where('actor_id', $doctor['id'])->first(), $plain);
        lostTotpDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_started')->where('actor_id', $doctor['id'])->first(), lostTotpSecretFromUri($uri));
        lostTotpDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_started')->where('actor_id', $doctor['id'])->first(), 'otpauth://');
    });

    it('does not mutate another user factor from a recovery-code session', function () {
        $owner = lostTotpInsertUser();
        $other = lostTotpInsertUser();
        lostTotpInsertCode($other['id'], $other['factor_id']);
        $plain = lostTotpInsertCode($owner['id'], $owner['factor_id']);
        $session = lostTotpCompleteRecovery(lostTotpDoctorChallenge($owner['phone'], $owner['password']), $plain);

        $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']))->assertOk();

        expect(lostTotpActiveId($other['id']))->toBe($other['factor_id'])
            ->and(lostTotpPendingId($other['id']))->toBeNull()
            ->and(lostTotpVerifiedCount($other['id']))->toBe(1)
            ->and(lostTotpUnusedCodes($other['id']))->toBe(1)
            ->and(lostTotpPendingId($owner['id']))->not->toBeNull();
    });

    it('returns 422 when the new totp is wrong and leaves the old factor active', function () {
        $doctor = lostTotpInsertUser();
        $plain = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $session = lostTotpCompleteRecovery(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $plain);
        $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']));
        $enroll->assertOk();
        $pendingId = lostTotpPendingId($doctor['id']);

        $response = $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => '000000',
        ], lostTotpBearer($session['token']));

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(lostTotpActiveId($doctor['id']))->toBe($doctor['factor_id'])
            ->and(lostTotpPendingId($doctor['id']))->toBe($pendingId)
            ->and(lostTotpVerifiedCount($doctor['id']))->toBe(1)
            ->and(DB::table('mfa_factors')->where('id', $doctor['factor_id'])->value('disabled_at'))->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_confirmed')->where('actor_id', $doctor['id'])->count())->toBe(0);
        lostTotpDoesNotLeak($response->json(), $plain);
        lostTotpDoesNotLeak($response->json(), lostTotpSecretFromUri((string) $enroll->json('data.provisioning_uri')));
    });

    it('returns 200 when the new totp confirms and disables the old factor atomically', function () {
        $doctor = lostTotpInsertUser();
        $unusedOld = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $usedOld = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $totpSession = lostTotpCompleteTotp(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $doctor['totp_secret']);
        $session = lostTotpCompleteRecovery(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $usedOld);
        $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']));
        $enroll->assertOk();
        $uri = (string) $enroll->json('data.provisioning_uri');
        $newSecret = lostTotpSecretFromUri($uri);
        $newFactorId = (string) $enroll->json('data.factor_id');
        $code = app(TotpVerifier::class)->codeAt($newSecret, app(Clock::class)->now());

        $stolen = $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => $code,
        ], lostTotpBearer($totpSession));
        $stolen->assertUnprocessable();
        expect(lostTotpActiveId($doctor['id']))->toBe($doctor['factor_id'])
            ->and(lostTotpPendingId($doctor['id']))->toBe($newFactorId);

        $confirm = $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => $code,
        ], lostTotpBearer($session['token']));

        $confirm->assertOk()
            ->assertJsonPath('data.verified', true);
        $freshCodes = $confirm->json('data.recovery_codes');
        expect($freshCodes)->toBeArray()->toHaveCount(8)
            ->and(lostTotpActiveId($doctor['id']))->toBe($newFactorId)
            ->and(lostTotpPendingId($doctor['id']))->toBeNull()
            ->and(lostTotpVerifiedCount($doctor['id']))->toBe(1)
            ->and(DB::table('mfa_factors')->where('id', $doctor['factor_id'])->value('disabled_at'))->not->toBeNull()
            ->and((string) DB::table('auth_sessions')->where('id', $session['session_id'])->value('assurance_level'))->toBe('aal2_totp')
            ->and(DB::table('auth_sessions')->where('id', $session['session_id'])->value('revoked_at'))->toBeNull()
            ->and((int) DB::table('users')->where('id', $doctor['id'])->value('credential_version'))->toBe(1)
            ->and(lostTotpUnusedCodes($doctor['id']))->toBe(8)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_confirmed')->where('actor_id', $doctor['id'])->count())->toBe(1);
        $this->getJson('/api/v1/me', lostTotpBearer($totpSession))->assertUnauthorized();
        $this->getJson('/api/v1/me', lostTotpBearer($session['token']))->assertOk();
        lostTotpDoesNotLeak($confirm->json(), $usedOld);
        lostTotpDoesNotLeak($confirm->json(), $unusedOld);
        lostTotpDoesNotLeak($confirm->json(), $newSecret);
        lostTotpDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_confirmed')->where('actor_id', $doctor['id'])->first(), $newSecret);
        lostTotpDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_confirmed')->where('actor_id', $doctor['id'])->first(), $freshCodes[0]);
        lostTotpDoesNotLeak(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_confirmed')->where('actor_id', $doctor['id'])->first(), 'otpauth://');
    });

    it('accepts the new totp and recovery codes and rejects the old ones after replacement', function () {
        $doctor = lostTotpInsertUser();
        $unusedOld = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $usedOld = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $session = lostTotpCompleteRecovery(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $usedOld);
        $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']));
        $enroll->assertOk();
        $newSecret = lostTotpSecretFromUri((string) $enroll->json('data.provisioning_uri'));
        $confirm = $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => app(TotpVerifier::class)->codeAt($newSecret, app(Clock::class)->now()),
        ], lostTotpBearer($session['token']));
        $confirm->assertOk();
        $fresh = $confirm->json('data.recovery_codes');
        expect($fresh)->toBeArray()->not->toBeEmpty();

        $oldChallenge = lostTotpDoctorChallenge($doctor['phone'], $doctor['password']);
        $oldTotp = $this->postJson('/api/v1/auth/mfa/challenges/'.$oldChallenge.'/verify', [
            'code' => app(TotpVerifier::class)->codeAt($doctor['totp_secret'], app(Clock::class)->now()),
        ]);
        $oldTotp->assertUnprocessable();

        $newChallenge = lostTotpDoctorChallenge($doctor['phone'], $doctor['password']);
        $newTotp = $this->postJson('/api/v1/auth/mfa/challenges/'.$newChallenge.'/verify', [
            'code' => app(TotpVerifier::class)->codeAt($newSecret, app(Clock::class)->now()),
        ]);
        $newTotp->assertOk()->assertJsonPath('data.assurance_level', 'aal2_totp');

        $oldCodeChallenge = lostTotpDoctorChallenge($doctor['phone'], $doctor['password']);
        $oldCode = $this->postJson('/api/v1/auth/mfa/challenges/'.$oldCodeChallenge.'/verify', [
            'recovery_code' => $unusedOld,
        ]);
        $oldCode->assertUnprocessable();

        $freshChallenge = lostTotpDoctorChallenge($doctor['phone'], $doctor['password']);
        $freshCode = $this->postJson('/api/v1/auth/mfa/challenges/'.$freshChallenge.'/verify', [
            'recovery_code' => $fresh[0],
        ]);
        $freshCode->assertOk()->assertJsonPath('data.assurance_level', 'aal2_recovery_code');
        lostTotpDoesNotLeak($freshCode->json(), $fresh[0]);
        lostTotpDoesNotLeak($oldCode->json(), $unusedOld);
    });

    it('returns 422 when the same recovery-code proof is reused after confirmation', function () {
        $doctor = lostTotpInsertUser();
        $plain = lostTotpInsertCode($doctor['id'], $doctor['factor_id']);
        $session = lostTotpCompleteRecovery(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $plain);
        $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']));
        $enroll->assertOk();
        $newSecret = lostTotpSecretFromUri((string) $enroll->json('data.provisioning_uri'));
        $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => app(TotpVerifier::class)->codeAt($newSecret, app(Clock::class)->now()),
        ], lostTotpBearer($session['token']))->assertOk();

        $replayEnroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($session['token']));
        $replayConfirm = $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => app(TotpVerifier::class)->codeAt($newSecret, app(Clock::class)->now()),
        ], lostTotpBearer($session['token']));

        $replayEnroll->assertUnprocessable()->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        $replayConfirm->assertUnprocessable()->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect((string) DB::table('auth_sessions')->where('id', $session['session_id'])->value('assurance_level'))->toBe('aal2_totp')
            ->and(lostTotpVerifiedCount($doctor['id']))->toBe(1)
            ->and(lostTotpPendingId($doctor['id']))->toBeNull();
    });

    it('still enrolls and confirms totp for a user who has no factor', function () {
        $patient = lostTotpInsertPasswordOnly('patient');
        $login = $this->postJson('/api/v1/auth/login', [
            'phone' => $patient['phone'],
            'password' => $patient['password'],
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ]);
        $login->assertOk()->assertJsonPath('data.assurance_level', 'aal1_password');
        $token = (string) $login->json('data.access_token');

        $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], lostTotpBearer($token));
        $enroll->assertOk();
        $secret = lostTotpSecretFromUri((string) $enroll->json('data.provisioning_uri'));
        $code = app(TotpVerifier::class)->codeAt($secret, app(Clock::class)->now());

        $confirm = $this->postJson('/api/v1/auth/mfa/totp/confirm', [
            'code' => $code,
        ], lostTotpBearer($token));

        $confirm->assertOk()->assertJsonPath('data.verified', true);
        expect($confirm->json('data.recovery_codes'))->toBeArray()->toHaveCount(8)
            ->and(lostTotpPendingId($patient['id']))->toBeNull()
            ->and(lostTotpVerifiedCount($patient['id']))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_enroll_confirmed')->where('actor_id', $patient['id'])->count())->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_replace_confirmed')->where('actor_id', $patient['id'])->count())->toBe(0);
    });

    it('still disables an active totp factor with the current authenticator code', function () {
        $doctor = lostTotpInsertUser();
        $token = lostTotpCompleteTotp(lostTotpDoctorChallenge($doctor['phone'], $doctor['password']), $doctor['totp_secret']);
        $later = app(Clock::class)->now()->modify('+60 seconds');
        app()->instance(Clock::class, new FrozenClock($later));
        $code = app(TotpVerifier::class)->codeAt($doctor['totp_secret'], $later);

        $response = $this->postJson('/api/v1/auth/mfa/totp/disable', [
            'code' => $code,
        ], lostTotpBearer($token));

        $response->assertOk()->assertJsonPath('data.disabled', true);
        expect(lostTotpActiveId($doctor['id']))->toBeNull()
            ->and(lostTotpPendingId($doctor['id']))->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'auth.mfa_disabled')->where('actor_id', $doctor['id'])->count())->toBe(1);
    });
});
