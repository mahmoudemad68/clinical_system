<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{Idempotency-Key: string}
 */
function recoveryApplyIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}

function recoveryApplyDispatchOutbox(): void
{
    app(OutboxDispatcher::class)->dispatchBatch();
}

function recoveryApplyOtp(string $purpose): string
{
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    return $sms->lastCodeByPurpose[$purpose];
}

/**
 * @return array{id: string, phone: string, password: string, totp_secret: string}
 */
function recoveryApplyInsertPrivileged(string $accountType): array
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

    return [
        'id' => $userId,
        'phone' => $phone,
        'password' => $password,
        'totp_secret' => $secret,
    ];
}

/**
 * @return array{id: string, phone: string, token: string}
 */
function recoveryApplyRegisterActivePatient(string $key): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);
    $payload = [
        'name' => 'Synthetic Patient',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];

    test()->postJson('/api/v1/auth/registrations', $payload, recoveryApplyIdem('reg-'.$key))->assertCreated();
    recoveryApplyDispatchOutbox();
    $verify = test()->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => DB::table('otp_requests')->where('purpose', 'registration')->orderByDesc('created_at')->value('id'),
        'code' => recoveryApplyOtp('registration'),
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone',
    ], recoveryApplyIdem('ver-'.$key));
    $verify->assertOk();
    $userId = (string) $verify->json('data.user_id');
    DB::table('users')->where('id', $userId)->update([
        'status' => 'active',
        'phone_verified_at' => now('UTC'),
    ]);

    return [
        'id' => $userId,
        'phone' => $phone,
        'token' => (string) $verify->json('data.access_token'),
    ];
}

function recoveryApplyLoginAdminWeb(string $phone, string $password, string $totpSecret): void
{
    test()->withCredentials();
    test()->getJson('/api/v1/auth/csrf')->assertOk();
    $csrf = csrf_token();
    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'admin_web',
        'platform' => 'web',
        'device_label' => 'admin-browser',
        '_token' => $csrf,
    ], ['X-CSRF-TOKEN' => $csrf]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

    $code = app(TotpVerifier::class)->codeAt($totpSecret, app(Clock::class)->now());
    $csrf = csrf_token();
    $mfa = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'code' => $code,
        '_token' => $csrf,
    ], ['X-CSRF-TOKEN' => $csrf]);
    $mfa->assertOk()->assertJsonPath('data.session_kind', 'admin_cookie');

    Auth::guard('web')->forgetUser();
    recoveryApplyPinAdminCookie();
}

function recoveryApplyPinAdminCookie(): void
{
    test()->withCredentials()
        ->withCookie((string) config('session.cookie'), (string) session()->getId());
}

function recoveryApplyLoginDoctor(string $phone, string $password, string $totpSecret): string
{
    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'doctor_desktop',
        'platform' => 'linux',
        'device_label' => 'clinic-pc',
    ]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

    $code = app(TotpVerifier::class)->codeAt($totpSecret, app(Clock::class)->now());
    $ok = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'code' => $code,
    ]);
    $ok->assertOk();

    return (string) $ok->json('data.access_token');
}

function recoveryApplyComplete(string $phone, string $newPassword, string $clientClass, string $platform, string $key, string $expectedStatus): string
{
    test()->postJson('/api/v1/auth/recovery/start', [
        'phone' => $phone,
        'language' => 'en',
    ])->assertOk();
    recoveryApplyDispatchOutbox();

    $challengeId = (string) DB::table('otp_requests')->where('purpose', 'recovery')->orderByDesc('created_at')->value('id');
    test()->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => $challengeId,
        'code' => recoveryApplyOtp('recovery'),
        'client_class' => $clientClass,
        'platform' => $platform,
        'device_label' => 'recovery',
    ], recoveryApplyIdem('rec-otp-'.$key))->assertOk()->assertJsonPath('data.status', 'recovery_verified');

    test()->postJson('/api/v1/auth/recovery/complete', [
        'challenge_id' => $challengeId,
        'code' => recoveryApplyOtp('recovery'),
        'password' => $newPassword,
    ], recoveryApplyIdem('rec-complete-'.$key))->assertOk()->assertJsonPath('data.status', $expectedStatus);

    return (string) DB::table('recovery_requests')->where('otp_id', $challengeId)->value('id');
}

function recoveryApplyPost(?string $requestId, array $headers = [], bool $cookieCsrf = false): TestResponse
{
    $body = [];
    if ($cookieCsrf) {
        recoveryApplyPinAdminCookie();
        $csrf = csrf_token();
        $body['_token'] = $csrf;
        $headers['X-CSRF-TOKEN'] = $csrf;
    }

    return test()->postJson(
        '/api/v1/auth/recovery/requests/'.($requestId ?? '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09').'/apply',
        $body,
        $headers,
    );
}

function recoveryApplyUnchanged(string $requestId, string $userId, int $credentialVersion, string $status): void
{
    $row = DB::table('recovery_requests')->where('id', $requestId)->first();
    $user = DB::table('users')->where('id', $userId)->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->status)->toBe($status)
        ->and($row->applied_at)->toBeNull()
        ->and((int) $user->credential_version)->toBe($credentialVersion);
}

describe('privileged recovery apply HTTP matrix', function () {
    it('returns 401 when an unauthenticated caller posts apply', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'unauth', 'manual_review');

        $response = recoveryApplyPost($requestId);

        $response->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
        recoveryApplyUnchanged($requestId, $subject['id'], 1, 'manual_review');
    });

    it('returns 404 when a patient posts apply and does not mutate recovery', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'patient', 'manual_review');
        $patient = recoveryApplyRegisterActivePatient('patient-deny');

        $response = recoveryApplyPost($requestId, ['Authorization' => 'Bearer '.$patient['token']]);

        $response->assertNotFound()
            ->assertJsonPath('errors.0.code', 'NOT_FOUND');
        recoveryApplyUnchanged($requestId, $subject['id'], 1, 'manual_review');
        expect(DB::table('audit_events')->where('event_name', 'auth.recovery_completed')->count())->toBe(0);
    });

    it('returns 404 when an AAL1 admin posts apply and does not mutate recovery', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'aal1', 'manual_review');
        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);
        DB::table('auth_sessions')->where('user_id', $admin['id'])->update([
            'assurance_level' => 'aal1_password',
        ]);

        $response = recoveryApplyPost($requestId, cookieCsrf: true);

        $response->assertNotFound()
            ->assertJsonPath('errors.0.code', 'NOT_FOUND');
        recoveryApplyUnchanged($requestId, $subject['id'], 1, 'manual_review');
        expect(DB::table('audit_events')->where('event_name', 'auth.recovery_completed')->count())->toBe(0);
    });

    it('returns 404 when an AAL2 doctor posts apply and does not mutate recovery', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'doctor-aal2', 'manual_review');
        $other = recoveryApplyInsertPrivileged('doctor');
        $token = recoveryApplyLoginDoctor($other['phone'], $other['password'], $other['totp_secret']);

        $response = recoveryApplyPost($requestId, ['Authorization' => 'Bearer '.$token]);

        $response->assertNotFound()
            ->assertJsonPath('errors.0.code', 'NOT_FOUND');
        recoveryApplyUnchanged($requestId, $subject['id'], 1, 'manual_review');
        expect(DB::table('audit_events')->where('event_name', 'auth.recovery_completed')->count())->toBe(0);
    });

    it('returns 422 when an AAL2 admin applies a recovery still inside cooling-off', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('cool-early');
        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'cool-early', 'cooling_off');
        $row = DB::table('recovery_requests')->where('id', $requestId)->first();
        expect((string) $row->status)->toBe('cooling_off')
            ->and($row->applied_at)->toBeNull();

        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);

        $response = recoveryApplyPost($requestId, cookieCsrf: true);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        recoveryApplyUnchanged($requestId, $patient['id'], 1, 'cooling_off');
        expect((int) DB::table('users')->where('id', $patient['id'])->value('credential_version'))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.recovery_completed')->count())->toBe(0);
    });

    it('returns 200 once when an AAL2 admin applies an eligible manual_review recovery', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $subjectToken = recoveryApplyLoginDoctor($subject['phone'], $subject['password'], $subject['totp_secret']);
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'apply-ok', 'manual_review');
        $completeStatus = DB::table('recovery_requests')->where('id', $requestId)->value('status');
        expect($completeStatus)->toBe('manual_review');

        test()->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$subjectToken])->assertOk();

        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);

        $first = recoveryApplyPost($requestId, cookieCsrf: true);
        $first->assertOk()->assertJsonPath('data.status', 'applied');

        $row = DB::table('recovery_requests')->where('id', $requestId)->first();
        $user = DB::table('users')->where('id', $subject['id'])->first();
        expect((string) $row->status)->toBe('applied')
            ->and($row->applied_at)->not->toBeNull()
            ->and((int) $user->credential_version)->toBe(2);

        test()->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$subjectToken])->assertUnauthorized();
        expect(DB::table('auth_sessions')->where('user_id', $subject['id'])->whereNull('revoked_at')->count())->toBe(0)
            ->and(DB::table('user_devices')->where('user_id', $subject['id'])->whereNull('revoked_at')->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.recovery_completed')->where('object_id', $subject['id'])->count())->toBe(1)
            ->and(DB::table('notifications')->where('notifiable_id', $subject['id'])->pluck('data')->implode(''))->toContain('auth.recovery_applied');

        $replay = recoveryApplyPost($requestId, cookieCsrf: true);
        $replay->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect((int) DB::table('users')->where('id', $subject['id'])->value('credential_version'))->toBe(2)
            ->and(DB::table('audit_events')->where('event_name', 'auth.recovery_completed')->where('object_id', $subject['id'])->count())->toBe(1);
    });

    it('returns 200 when an AAL2 admin applies cooling-off only after the delay elapses', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('cool-due');
        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'cool-due', 'cooling_off');
        expect(DB::table('recovery_requests')->where('id', $requestId)->value('status'))->toBe('cooling_off');

        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);

        recoveryApplyPost($requestId, cookieCsrf: true)->assertUnprocessable();
        recoveryApplyUnchanged($requestId, $patient['id'], 1, 'cooling_off');

        $until = new DateTimeImmutable((string) DB::table('recovery_requests')->where('id', $requestId)->value('cooling_off_until'));
        app()->instance(Clock::class, new FrozenClock($until->modify('+1 second')));

        $applied = recoveryApplyPost($requestId, cookieCsrf: true);
        $applied->assertOk()->assertJsonPath('data.status', 'applied');
        expect((int) DB::table('users')->where('id', $patient['id'])->value('credential_version'))->toBe(2)
            ->and((string) DB::table('recovery_requests')->where('id', $requestId)->value('status'))->toBe('applied');
    });
});
