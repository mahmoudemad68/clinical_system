<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;

/**
 * @return array<string, mixed>
 */
function clinicLocationBody(
    string $publicName = 'Cairo Clinic',
    string $address = '1 Tahrir Square, Cairo',
    float $latitude = 30.0444,
    float $longitude = 31.2357,
    string $countryCode = 'EG',
): array {
    return [
        'public_name' => $publicName,
        'address' => $address,
        'country_code' => $countryCode,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ];
}

/**
 * @return array{Idempotency-Key: string}
 */
function clinicIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}

function clinicApproveDoctorProfile(string $userId): string
{
    $doctorId = (string) DB::table('doctor_profiles')->where('user_id', $userId)->value('id');
    DB::table('doctor_profiles')->where('id', $doctorId)->update([
        'verification_status' => 'approved',
        'approved_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    return $doctorId;
}

/**
 * @return array{token: string, payload: array<string, string>, user_id: string, totp_secret: string, doctor_id: string}
 */
function clinicApprovedDoctor(string $key, string $specialtyCode = 'gp_clinic'): array
{
    $safe = strtolower(preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key);
    $specialty = doctorsSeedSpecialty($specialtyCode.'_'.$safe);
    $session = doctorsActiveSession($key);
    test()->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']),
        doctorsAuth($session['token']) + doctorsIdem('clinic-onboard-'.$key),
    )->assertCreated();

    $session['doctor_id'] = clinicApproveDoctorProfile($session['user_id']);

    return $session;
}

function clinicSetDoctorVerificationStatus(string $userId, string $status): void
{
    DB::table('doctor_profiles')->where('user_id', $userId)->update([
        'verification_status' => $status,
        'updated_at' => now('UTC'),
    ]);
}

/**
 * @return array{id: string, phone: string, password: string, user_id: string}
 */
function clinicInsertSecretary(string $key): array
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
        'name' => 'Synthetic Secretary '.$key,
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsed)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsed)),
        'phone_key_version' => 1,
        'password_hash' => app(PasswordHasher::class)->hash($password),
        'account_type' => 'secretary',
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
        'user_id' => $userId,
        'phone' => $phone,
        'password' => $password,
    ];
}

/**
 * @param  array{phone: string, password: string}  $secretary
 */
function clinicSecretaryLogin(array $secretary): void
{
    clinicClearBrowserSession();
    test()->withCredentials();
    test()->getJson('/api/v1/auth/csrf')->assertOk();
    $csrf = csrf_token();
    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $secretary['phone'],
        'password' => $secretary['password'],
        'client_class' => 'admin_web',
        'platform' => 'web',
        'device_label' => 'clinic-secretary',
        '_token' => $csrf,
    ], ['X-CSRF-TOKEN' => $csrf]);
    $login->assertOk()->assertJsonPath('data.session_kind', 'admin_cookie');

    Auth::guard('web')->forgetUser();
    clinicSecretaryPinCookie();
}

function clinicSecretaryPinCookie(): void
{
    Auth::guard('web')->forgetUser();
    test()->withCredentials()
        ->withCookie((string) config('session.cookie'), (string) session()->getId());
}

function clinicClearBrowserSession(): void
{
    Auth::guard('web')->forgetUser();
    auth()->forgetGuards();
    test()->flushHeaders();
    test()->clearBrowserSession();
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function clinicSecretaryPostJson(string $uri, array $body = [], array $headers = []): TestResponse
{
    clinicSecretaryPinCookie();
    $csrf = csrf_token();

    return test()->postJson($uri, $body + ['_token' => $csrf], ['X-CSRF-TOKEN' => $csrf] + $headers);
}

function clinicSecretaryGetJson(string $uri): TestResponse
{
    clinicSecretaryPinCookie();

    return test()->getJson($uri);
}

function clinicDoctorActor(string $userId): ActorContext
{
    return new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Doctor,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal2Totp,
        1,
        null,
        Identifier::fromTrusted($userId),
        [],
        Capabilities::AUTHENTICATED_SELF,
    );
}

function clinicSecretaryActor(string $userId): ActorContext
{
    return new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Secretary,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal1Password,
        1,
        null,
        Identifier::fromTrusted($userId),
        [],
        Capabilities::AUTHENTICATED_SELF,
    );
}

function clinicEraseOperator(): ActorContext
{
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);

    return new ActorContext(
        Identifier::fromTrusted((string) $admin->id),
        AccountType::Admin,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal2Totp,
        1,
        null,
        Identifier::fromTrusted((string) $admin->id),
        [],
        Capabilities::forActor('admin', true),
    );
}
