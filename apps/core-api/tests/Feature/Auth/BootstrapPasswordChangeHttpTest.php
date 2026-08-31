<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    $path = bootstrapPwUriPath();
    if (is_file($path)) {
        unlink($path);
    }
});

function bootstrapPwPassword(): string
{
    return 'correct-horse-battery';
}

function bootstrapPwReplacement(): string
{
    return 'replaced-horse-battery';
}

function bootstrapPwUriPath(): string
{
    return storage_path('app/private/bootstrap-totp.uri');
}

function bootstrapPwMustChange(string $userId): bool
{
    return (bool) User::query()->whereKey($userId)->value('password_must_change');
}

function bootstrapPwVersion(string $userId): int
{
    return (int) DB::table('users')->where('id', $userId)->value('credential_version');
}

/**
 * @return array{id: string, phone: string, secret: string}
 */
function bootstrapPwConfirmed(mixed $test): array
{
    $phone = (new SyntheticEgyptianData)->mobileNumber();
    $test->artisan('identity:bootstrap-admin', ['phone' => $phone])
        ->expectsQuestion('Password', bootstrapPwPassword())
        ->assertSuccessful();

    $userId = (string) DB::table('users')->where('account_type', AccountType::Admin->value)->value('id');
    expect($userId)->not->toBe('');

    $row = DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->first();
    expect($row)->not->toBeNull();

    $secret = app(FieldEncryptor::class)->decrypt(
        'mfa_secret',
        BinaryColumn::asString($row->secret_ciphertext),
    );
    $code = app(TotpVerifier::class)->codeAt($secret, app(Clock::class)->now());

    $test->artisan('identity:bootstrap-admin', ['phone' => $phone, '--confirm' => true])
        ->expectsQuestion('Confirmation TOTP', $code)
        ->assertSuccessful();

    return ['id' => $userId, 'phone' => $phone, 'secret' => $secret];
}

function bootstrapPwCsrfHeaders(): array
{
    $csrf = csrf_token();

    return [$csrf, ['X-CSRF-TOKEN' => $csrf]];
}

function bootstrapPwLoginAdmin(string $phone, string $password, string $totpSecret): TestResponse
{
    test()->withCredentials();
    test()->getJson('/api/v1/auth/csrf')->assertOk();
    [$csrf, $headers] = bootstrapPwCsrfHeaders();
    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'admin_web',
        'platform' => 'web',
        'device_label' => 'admin-browser',
        '_token' => $csrf,
    ], $headers);

    if ($login->status() !== 200 || $login->json('data.status') !== 'mfa_required') {
        return $login;
    }

    $code = app(TotpVerifier::class)->codeAt($totpSecret, app(Clock::class)->now());
    [$csrf, $headers] = bootstrapPwCsrfHeaders();
    $mfa = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'code' => $code,
        '_token' => $csrf,
    ], $headers);

    Auth::guard('web')->forgetUser();
    test()->withCredentials()
        ->withCookie((string) config('session.cookie'), (string) session()->getId());

    return $mfa;
}

function bootstrapPwPost(string $uri, array $data = [], array $headers = []): TestResponse
{
    [$csrf, $csrfHeaders] = bootstrapPwCsrfHeaders();

    return test()->postJson($uri, $data + ['_token' => $csrf], array_merge($csrfHeaders, $headers));
}

/**
 * @return array{id: string, phone: string, password: string, totp_secret: string}
 */
function bootstrapPwInsertDoctor(): array
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

function bootstrapPwAuditDoesNotLeak(string $eventName, string ...$secrets): void
{
    $row = DB::table('audit_events')->where('event_name', $eventName)->orderByDesc('occurred_at')->first();
    expect($row)->not->toBeNull();

    $metadata = $row->metadata;
    $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;
    expect($decoded)->toBeArray()
        ->and($decoded['reason_code'] ?? null)->toBe('password_change');

    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($encoded)->toBeString();
    foreach ($secrets as $secret) {
        expect($encoded)->not->toContain($secret);
    }
}

describe('bootstrap immediate password change', function () {
    it('persists password-change-required on a freshly bootstrapped admin', function () {
        $admin = bootstrapPwConfirmed($this);

        expect(bootstrapPwMustChange($admin['id']))->toBeTrue()
            ->and(bootstrapPwVersion($admin['id']))->toBe(1)
            ->and((bool) User::query()->whereKey($admin['id'])->value('bootstrap_exempt'))->toBeTrue();
    });

    it('issues a restricted admin session after bootstrap password login and TOTP, not an unrestricted privileged session', function () {
        $admin = bootstrapPwConfirmed($this);
        test()->withCredentials();
        test()->getJson('/api/v1/auth/csrf')->assertOk();
        [$csrf, $headers] = bootstrapPwCsrfHeaders();
        $challenge = test()->postJson('/api/v1/auth/login', [
            'phone' => $admin['phone'],
            'password' => bootstrapPwPassword(),
            'client_class' => 'admin_web',
            'platform' => 'web',
            'device_label' => 'admin-browser',
            '_token' => $csrf,
        ], $headers);

        $challenge->assertOk()
            ->assertJsonPath('data.status', 'mfa_required')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token')
            ->assertJsonMissingPath('data.session_id')
            ->assertJsonMissingPath('data.password_must_change')
            ->assertJsonMissingPath('data.capabilities');

        $code = app(TotpVerifier::class)->codeAt($admin['secret'], app(Clock::class)->now());
        [$csrf, $headers] = bootstrapPwCsrfHeaders();
        $session = test()->postJson('/api/v1/auth/mfa/challenges/'.$challenge->json('data.challenge_id').'/verify', [
            'code' => $code,
            '_token' => $csrf,
        ], $headers);

        Auth::guard('web')->forgetUser();
        test()->withCredentials()
            ->withCookie((string) config('session.cookie'), (string) session()->getId());

        $session->assertOk()
            ->assertJsonPath('data.session_kind', 'admin_cookie')
            ->assertJsonPath('data.password_must_change', true)
            ->assertJsonPath('data.assurance_level', 'aal2_totp')
            ->assertJsonPath('data.capabilities', Capabilities::PASSWORD_CHANGE_REQUIRED)
            ->assertJsonMissingPath('data.refresh_token')
            ->assertJsonMissingPath('data.access_token');

        expect($session->json('data.capabilities'))->not->toContain(Capabilities::ACCESS_GRANT_ISSUE)
            ->and($session->json('data.capabilities'))->not->toContain(Capabilities::IDENTITY_ME_READ)
            ->and(bootstrapPwMustChange($admin['id']))->toBeTrue();
    });

    it('returns 404 from privileged identity routes while password change is required', function (string $method, string $path, array $payload) {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])
            ->assertOk()
            ->assertJsonPath('data.password_must_change', true);

        [$csrf, $headers] = bootstrapPwCsrfHeaders();
        $response = match ($method) {
            'get' => test()->getJson($path, $headers),
            'post' => test()->postJson($path, $payload + ['_token' => $csrf], $headers),
            default => throw new RuntimeException('Unsupported method '.$method),
        };

        $response->assertNotFound()
            ->assertJsonPath('errors.0.code', 'NOT_FOUND');
        expect(bootstrapPwMustChange($admin['id']))->toBeTrue()
            ->and(bootstrapPwVersion($admin['id']))->toBe(1);
    })->with([
        'me' => ['get', '/api/v1/me', []],
        'capabilities' => ['get', '/api/v1/me/capabilities', []],
        'sessions' => ['get', '/api/v1/auth/sessions', []],
        'totp enroll' => ['post', '/api/v1/auth/mfa/totp/enroll', []],
        'totp confirm' => ['post', '/api/v1/auth/mfa/totp/confirm', ['code' => '123456']],
        'recovery apply' => ['post', '/api/v1/auth/recovery/requests/0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c99/apply', []],
    ]);

    it('returns 401 when refresh is attempted instead of continuing the restricted bootstrap session', function () {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])->assertOk();

        $response = test()->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => 'not-a-bootstrap-refresh-token',
        ], ['Idempotency-Key' => 'clinic-test-idem-bootstrap-pw-refresh']);

        $response->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
        expect(bootstrapPwMustChange($admin['id']))->toBeTrue();
    });

    it('returns 422 when the replacement password is invalid and leaves the requirement set', function (string $newPassword) {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])->assertOk();

        $response = bootstrapPwPost('/api/v1/auth/password/change', [
            'current_password' => bootstrapPwPassword(),
            'new_password' => $newPassword,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        expect(bootstrapPwMustChange($admin['id']))->toBeTrue()
            ->and(bootstrapPwVersion($admin['id']))->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'auth.password_changed')->count())->toBe(0);
    })->with([
        'too short' => ['short'],
        'empty' => [''],
        'numeric only' => ['123456789012'],
    ]);

    it('clears password-change-required after a compliant password replacement', function () {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])->assertOk();

        $response = bootstrapPwPost('/api/v1/auth/password/change', [
            'current_password' => bootstrapPwPassword(),
            'new_password' => bootstrapPwReplacement(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.credential_rotated', true);
        expect(bootstrapPwMustChange($admin['id']))->toBeFalse()
            ->and(bootstrapPwVersion($admin['id']))->toBe(2)
            ->and(DB::table('auth_sessions')->where('user_id', $admin['id'])->whereNull('revoked_at')->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'auth.password_changed')->count())->toBe(1);

        bootstrapPwAuditDoesNotLeak(
            'auth.password_changed',
            bootstrapPwPassword(),
            bootstrapPwReplacement(),
            '$argon',
        );
    });

    it('rejects the bootstrap password after a successful replacement', function () {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])->assertOk();
        bootstrapPwPost('/api/v1/auth/password/change', [
            'current_password' => bootstrapPwPassword(),
            'new_password' => bootstrapPwReplacement(),
        ])->assertOk();

        test()->withCredentials();
        test()->getJson('/api/v1/auth/csrf')->assertOk();
        [$csrf, $headers] = bootstrapPwCsrfHeaders();
        $login = test()->postJson('/api/v1/auth/login', [
            'phone' => $admin['phone'],
            'password' => bootstrapPwPassword(),
            'client_class' => 'admin_web',
            'platform' => 'web',
            'device_label' => 'admin-browser',
            '_token' => $csrf,
        ], $headers);

        $login->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
    });

    it('authenticates the replacement password and still requires TOTP before a privileged session', function () {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])->assertOk();
        bootstrapPwPost('/api/v1/auth/password/change', [
            'current_password' => bootstrapPwPassword(),
            'new_password' => bootstrapPwReplacement(),
        ])->assertOk();

        $later = app(Clock::class)->now()->modify('+60 seconds');
        app()->instance(Clock::class, new FrozenClock($later));

        test()->withCredentials();
        test()->getJson('/api/v1/auth/csrf')->assertOk();
        [$csrf, $headers] = bootstrapPwCsrfHeaders();
        $challenge = test()->postJson('/api/v1/auth/login', [
            'phone' => $admin['phone'],
            'password' => bootstrapPwReplacement(),
            'client_class' => 'admin_web',
            'platform' => 'web',
            'device_label' => 'admin-browser',
            '_token' => $csrf,
        ], $headers);

        $challenge->assertOk()
            ->assertJsonPath('data.status', 'mfa_required')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.session_id');

        $code = app(TotpVerifier::class)->codeAt($admin['secret'], $later);
        [$csrf, $headers] = bootstrapPwCsrfHeaders();
        $session = test()->postJson('/api/v1/auth/mfa/challenges/'.$challenge->json('data.challenge_id').'/verify', [
            'code' => $code,
            '_token' => $csrf,
        ], $headers);

        Auth::guard('web')->forgetUser();
        test()->withCredentials()
            ->withCookie((string) config('session.cookie'), (string) session()->getId());

        $session->assertOk()
            ->assertJsonPath('data.session_kind', 'admin_cookie')
            ->assertJsonPath('data.password_must_change', false)
            ->assertJsonPath('data.assurance_level', 'aal2_totp')
            ->assertJsonPath('data.capabilities', Capabilities::forActor('admin', true));

        test()->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.status', 'active');
    });

    it('does not bump credential_version when the password-change request is replayed', function () {
        $admin = bootstrapPwConfirmed($this);
        bootstrapPwLoginAdmin($admin['phone'], bootstrapPwPassword(), $admin['secret'])->assertOk();
        bootstrapPwPost('/api/v1/auth/password/change', [
            'current_password' => bootstrapPwPassword(),
            'new_password' => bootstrapPwReplacement(),
        ])->assertOk();

        expect(bootstrapPwVersion($admin['id']))->toBe(2)
            ->and(bootstrapPwMustChange($admin['id']))->toBeFalse();

        $replay = bootstrapPwPost('/api/v1/auth/password/change', [
            'current_password' => bootstrapPwPassword(),
            'new_password' => bootstrapPwReplacement(),
        ]);

        $replay->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
        expect(bootstrapPwVersion($admin['id']))->toBe(2)
            ->and(bootstrapPwMustChange($admin['id']))->toBeFalse()
            ->and(DB::table('audit_events')->where('event_name', 'auth.password_changed')->count())->toBe(1);
    });

    it('leaves ordinary non-bootstrap login and /me access unchanged', function () {
        $doctor = bootstrapPwInsertDoctor();

        $login = test()->postJson('/api/v1/auth/login', [
            'phone' => $doctor['phone'],
            'password' => $doctor['password'],
            'client_class' => 'doctor_desktop',
            'platform' => 'linux',
            'device_label' => 'clinic-pc',
        ]);
        $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

        $code = app(TotpVerifier::class)->codeAt($doctor['totp_secret'], app(Clock::class)->now());
        $session = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
            'code' => $code,
        ]);

        $session->assertOk()
            ->assertJsonPath('data.session_kind', 'device')
            ->assertJsonPath('data.password_must_change', false)
            ->assertJsonPath('data.capabilities', Capabilities::forActor('doctor', true));

        $token = (string) $session->json('data.access_token');
        test()->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect(bootstrapPwMustChange($doctor['id']))->toBeFalse()
            ->and(bootstrapPwVersion($doctor['id']))->toBe(1);
    });
});
