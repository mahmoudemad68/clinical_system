<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;

/**
 * @return array{name: string, phone: string, national_id: string, password: string, registration: string}
 */
function pharmaciesSyntheticIdentity(): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);

    return [
        'name' => 'Synthetic Pharmacy',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => 'correct-horse-battery',
        'registration' => 'CR'.substr($nationalId, -8),
    ];
}

/**
 * @return array<string, mixed>
 */
function pharmaciesOnboardingBody(
    string $registration,
    string $phone,
    string $publicName = 'Synthetic Pharmacy',
    string $legalName = 'Synthetic Pharmacy LLC',
    string $branchName = 'Main Branch',
    string $address = '12 Test Street, Cairo',
    float $latitude = 30.0444,
    float $longitude = 31.2357,
    string $countryCode = 'EG',
): array {
    return [
        'legal_name' => $legalName,
        'public_name' => $publicName,
        'legal_registration_identifier' => $registration,
        'branch_public_name' => $branchName,
        'address' => $address,
        'country_code' => $countryCode,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'phone' => $phone,
    ];
}

/**
 * @return array<string, string>
 */
function pharmaciesAuth(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{Idempotency-Key: string}
 */
function pharmaciesIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}

/**
 * Direct membership row for PostgreSQL tenant-integrity tests. Not an HTTP API.
 *
 * @return array<string, mixed>
 */
function pharmaciesMembershipRow(
    string $organizationId,
    string $userId,
    ?string $branchId,
    string $role,
): array {
    $now = now('UTC');

    return [
        'id' => app(IdentityGenerator::class)->next()->value,
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'branch_id' => $branchId,
        'role' => $role,
        'status' => 'pending',
        'invited_at' => $now,
        'accepted_at' => $now,
        'revoked_at' => null,
        'inviter_user_id' => null,
        'revoker_user_id' => null,
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

/**
 * @return array{token: string, payload: array<string, string>, user_id: string, totp_secret: string}
 */
function pharmaciesActiveSession(string $key, string $status = 'active'): array
{
    auth()->forgetGuards();

    $payload = pharmaciesSyntheticIdentity();
    $protector = app(NationalIdProtector::class);
    $phone = $protector->phone($payload['phone']);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Pharmacy',
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($phone)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
        'phone_key_version' => 1,
        'password_hash' => app(PasswordHasher::class)->hash($payload['password']),
        'account_type' => 'pharmacy',
        'status' => $status,
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

    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $payload['phone'],
        'password' => $payload['password'],
        'client_class' => 'pharmacy_desktop',
        'platform' => 'linux',
        'device_label' => 'clinic-pc-'.$key,
    ]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

    $code = $totp->codeAt($secret, app(Clock::class)->now());
    $session = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'code' => $code,
    ]);
    $session->assertOk();
    $token = $session->json('data.access_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [
        'token' => $token,
        'payload' => $payload,
        'user_id' => $userId,
        'totp_secret' => $secret,
    ];
}

/**
 * @return array<string, mixed>
 */
function pharmaciesBranchBody(
    string $phone,
    string $publicName = 'Nasr City Branch',
    string $address = '5 Abbas El Akkad, Cairo',
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
        'phone' => $phone,
    ];
}

function pharmaciesApproveOrganization(string $organizationId): void
{
    $now = now('UTC');
    $org = DB::table('pharmacy_organizations')->where('id', $organizationId)->first();
    assert($org !== null);
    DB::table('pharmacy_organizations')->where('id', $organizationId)->update([
        'verification_status' => 'approved',
        'status' => 'active',
        'version' => ((int) $org->version) + 1,
        'updated_at' => $now,
    ]);
    $branch = DB::table('pharmacy_branches')->where('organization_id', $organizationId)->orderBy('created_at')->orderBy('id')->first();
    assert($branch !== null);
    DB::table('pharmacy_branches')->where('id', $branch->id)->update([
        'status' => 'active',
        'version' => ((int) $branch->version) + 1,
        'updated_at' => $now,
    ]);
    $membership = DB::table('pharmacy_memberships')
        ->where('organization_id', $organizationId)
        ->where('role', 'owner')
        ->first();
    assert($membership !== null);
    DB::table('pharmacy_memberships')->where('id', $membership->id)->update([
        'status' => 'active',
        'version' => ((int) $membership->version) + 1,
        'updated_at' => $now,
    ]);
}

/**
 * @return array{
 *     token: string,
 *     payload: array<string, string>,
 *     user_id: string,
 *     totp_secret: string,
 *     organization_id: string,
 *     branch_id: string,
 *     membership_id: string
 * }
 */
function pharmaciesApprovedOrganization(string $key): array
{
    $session = pharmaciesActiveSession($key);
    $response = test()->postJson(
        '/api/v1/pharmacy-organizations/onboarding',
        pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']),
        pharmaciesAuth($session['token']) + pharmaciesIdem('pbr-onboard-'.$key),
    );
    $response->assertCreated();
    $organizationId = (string) $response->json('data.organization_id');
    pharmaciesApproveOrganization($organizationId);

    return [
        ...$session,
        'organization_id' => $organizationId,
        'branch_id' => (string) $response->json('data.branch_id'),
        'membership_id' => (string) $response->json('data.membership_id'),
    ];
}

function pharmaciesPharmacyActor(string $userId): ActorContext
{
    return new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Pharmacy,
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

function pharmaciesUseHmacCurrentVersion(int $version): void
{
    clinicUseHmacCurrentVersion($version);
}
