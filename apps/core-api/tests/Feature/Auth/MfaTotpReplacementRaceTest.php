<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Services\CredentialIssuer;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

afterEach(function (): void {
    DB::unprepared('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
});

it('activates at most one totp factor when two connections confirm a replacement', function () {
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;
    $factorId = $ids->next()->value;
    $password = 'correct-horse-battery';

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Race Doctor',
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

    $plain = app(CredentialIssuer::class)->recoveryCode();
    DB::table('mfa_recovery_codes')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'factor_id' => $factorId,
        'code_hash' => BinaryColumn::bind(app(CredentialIssuer::class)->hashRecoveryCode($plain)),
        'consumed_at' => null,
        'created_at' => $now,
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'doctor_desktop',
        'platform' => 'linux',
        'device_label' => 'clinic-pc',
    ]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');
    $verify = $this->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'recovery_code' => $plain,
    ]);
    $verify->assertOk()->assertJsonPath('data.assurance_level', 'aal2_recovery_code');
    $token = (string) $verify->json('data.access_token');

    $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], ['Authorization' => 'Bearer '.$token]);
    $enroll->assertOk();
    $query = [];
    parse_str((string) parse_url((string) $enroll->json('data.provisioning_uri'), PHP_URL_QUERY), $query);
    $newSecret = (string) ($query['secret'] ?? '');
    $newCode = app(TotpVerifier::class)->codeAt($newSecret, app(Clock::class)->now());

    $pair = ConcurrentHttpPair::run([
        'op' => 'mfa_totp_confirm',
        'access_token' => $token,
        'code' => $newCode,
    ], [
        'op' => 'mfa_totp_confirm',
        'access_token' => $token,
        'code' => $newCode,
    ]);

    $leftStatus = (int) ($pair['left']['status'] ?? 0);
    $rightStatus = (int) ($pair['right']['status'] ?? 0);
    $successes = ($leftStatus === 200 ? 1 : 0) + ($rightStatus === 200 ? 1 : 0);
    $denied = ($leftStatus === 422 ? 1 : 0) + ($rightStatus === 422 ? 1 : 0);
    $verified = (int) DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->whereNotNull('verified_at')
        ->count();
    $pending = (int) DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->whereNull('verified_at')
        ->count();
    $oldDisabled = DB::table('mfa_factors')->where('id', $factorId)->value('disabled_at');

    expect($pair['left']['error'] ?? null)->toBeNull()
        ->and($pair['right']['error'] ?? null)->toBeNull()
        ->and($successes)->toBe(1)
        ->and($denied)->toBe(1)
        ->and($verified)->toBe(1)
        ->and($pending)->toBe(0)
        ->and($oldDisabled)->not->toBeNull()
        ->and((int) DB::table('users')->where('id', $userId)->value('credential_version'))->toBe(1)
        ->and((string) DB::table('auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')->value('assurance_level'))->toBe('aal2_totp');
});

it('rejects a second verified totp factor for the same user', function () {
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;
    $firstId = $ids->next()->value;
    $secondId = $ids->next()->value;
    $password = 'correct-horse-battery';
    $secret = app(TotpVerifier::class)->generateSecret();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Unique Doctor',
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

    $row = [
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
    ];
    DB::table('mfa_factors')->insert(['id' => $firstId] + $row);

    expect(fn () => DB::table('mfa_factors')->insert(['id' => $secondId] + $row))
        ->toThrow(UniqueConstraintViolationException::class);
});
