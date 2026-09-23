<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;

/**
 * @return array{name: string, phone: string, national_id: string, password: string}
 */
function doctorsSyntheticIdentity(): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);

    return [
        'name' => 'Synthetic Doctor',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => 'correct-horse-battery',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}
 */
function doctorsSeedSpecialty(string $code = 'general_practice', array $overrides = []): array
{
    $now = now('UTC');
    $existing = isset($overrides['id'])
        ? null
        : DB::table('specialties')->where('code', $code)->first();

    if ($existing !== null) {
        $updates = [];
        foreach (['label_ar', 'label_en', 'active', 'sort_order'] as $field) {
            if (array_key_exists($field, $overrides)) {
                $updates[$field] = $overrides[$field];
            }
        }
        if ($updates !== []) {
            $updates['updated_at'] = $now;
            DB::table('specialties')->where('id', $existing->id)->update($updates);
            $existing = DB::table('specialties')->where('id', $existing->id)->first();
        }

        return [
            'id' => (string) $existing->id,
            'code' => (string) $existing->code,
            'label_ar' => (string) $existing->label_ar,
            'label_en' => (string) $existing->label_en,
            'active' => (bool) $existing->active,
            'sort_order' => (int) $existing->sort_order,
        ];
    }

    $ids = app(IdentityGenerator::class);
    $row = array_merge([
        'id' => $ids->next()->value,
        'code' => $code,
        'label_ar' => 'طب الأسرة',
        'label_en' => 'General Practice',
        'active' => true,
        'sort_order' => 10,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::table('specialties')->insert($row);

    return $row;
}

/**
 * @return array<string, mixed>
 */
function doctorsOnboardingBody(string $nationalId, string $specialtyId, ?string $syndicate = null, string $name = 'Synthetic Doctor'): array
{
    $body = [
        'national_id' => $nationalId,
        'professional_display_name' => $name,
        'specialty_id' => $specialtyId,
    ];

    if ($syndicate !== null) {
        $body['syndicate_number'] = $syndicate;
    }

    return $body;
}

/**
 * @return array<string, string>
 */
function doctorsAuth(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{Idempotency-Key: string}
 */
function doctorsIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}

/**
 * @return array{token: string, payload: array<string, string>, user_id: string, totp_secret: string}
 */
function doctorsActiveSession(string $key, string $status = 'active'): array
{
    auth()->forgetGuards();
    test()->flushHeaders();
    test()->clearBrowserSession();

    $payload = doctorsSyntheticIdentity();
    $protector = app(NationalIdProtector::class);
    $phone = $protector->phone($payload['phone']);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Doctor',
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($phone)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
        'phone_key_version' => 1,
        'password_hash' => app(PasswordHasher::class)->hash($payload['password']),
        'account_type' => 'doctor',
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
        'client_class' => 'doctor_desktop',
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

function doctorsBindIdentityNationalId(string $userId, string $nationalId): void
{
    $protector = app(NationalIdProtector::class);
    $nid = $protector->nationalId($nationalId);
    $ids = app(IdentityGenerator::class);

    app(UserDirectory::class)->insertNationalId(
        $ids->next(),
        Identifier::fromTrusted($userId),
        $protector->encryptNationalId($nid),
        $protector->nationalIdHmac($nid),
        $protector->encryptionVersion(),
        $protector->hmacVersion(),
        now('UTC')->toDateTimeImmutable(),
    );
}
