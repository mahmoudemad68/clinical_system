<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\AuditedSensitiveDecryptor;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Crypto\AesGcmEnvelopeEncryptor;
use Modules\Platform\Services\Crypto\HkdfHmacHasher;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->isr014Snapshot = [
        'app.env' => config('app.env'),
        'identity.hmac.current_version' => config('identity.hmac.current_version'),
        'identity.encryption.current_version' => config('identity.encryption.current_version'),
        'identity.hmac.keys' => config('identity.hmac.keys'),
        'identity.encryption.keys' => config('identity.encryption.keys'),
    ];
    isr014ResetPersistedIdentityState();
});

afterEach(function (): void {
    config($this->isr014Snapshot);
    isr014RebindCrypto();
});

function isr014ResetPersistedIdentityState(): void
{
    foreach ([
        'mfa_recovery_codes',
        'mfa_challenges',
        'mfa_factors',
        'auth_refresh_consumptions',
        'auth_sessions',
        'user_devices',
        'otp_requests',
        'recovery_requests',
        'identity_profile_links',
        'identity_national_ids',
        'contextual_access_grants',
        'outbox_events',
        'notifications',
        'users',
    ] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
}

function isr014RebindCrypto(): void
{
    app()->forgetInstance(FieldEncryptor::class);
    app()->forgetInstance(HmacHasher::class);
    app()->forgetInstance(NationalIdProtector::class);
    app()->forgetInstance(AuditedSensitiveDecryptor::class);
}

function isr014UserRowByPhone(string $phoneRaw): object
{
    $protector = app(NationalIdProtector::class);
    $user = app(UserDirectory::class)->findByPhoneHmacs($protector->phoneLookupHmacs($protector->phone($phoneRaw)));
    expect($user)->not->toBeNull();
    $row = DB::table('users')->where('id', $user->id->value)->first();
    expect($row)->not->toBeNull();

    return $row;
}

function isr014UseCurrentVersion(int $version): void
{
    config([
        'identity.hmac.current_version' => $version,
        'identity.encryption.current_version' => $version,
    ]);
    isr014RebindCrypto();
}

/**
 * @return array{enc: AesGcmEnvelopeEncryptor, hmac: HkdfHmacHasher}
 */
function isr014VersionedCrypto(int $version): array
{
    $encKeys = [
        1 => (string) config('identity.encryption.keys.1'),
        2 => (string) config('identity.encryption.keys.2'),
    ];
    $hmacKeys = [
        1 => (string) config('identity.hmac.keys.1'),
        2 => (string) config('identity.hmac.keys.2'),
    ];

    return [
        'enc' => new AesGcmEnvelopeEncryptor($encKeys, $version),
        'hmac' => new HkdfHmacHasher($hmacKeys, $version),
    ];
}

/**
 * @return array{id: string, phone: string, national_id: string, password: string}
 */
function isr014InsertIdentity(int $cryptoVersion, ?string $phoneRaw = null, ?string $nationalIdRaw = null): array
{
    $synthetic = new SyntheticEgyptianData;
    $phoneRaw ??= $synthetic->mobileNumber();
    $nationalIdRaw ??= $synthetic->nationalId();
    $password = 'correct-horse-battery';
    $phone = app(NationalIdProtector::class)->phone($phoneRaw);
    $nationalId = app(NationalIdProtector::class)->nationalId($nationalIdRaw);
    $crypto = isr014VersionedCrypto($cryptoVersion);
    $now = app(Clock::class)->now();
    $userId = app(IdentityGenerator::class)->next();

    app(UserDirectory::class)->insertUser(
        new UserAccount(
            $userId,
            'Rotation Patient',
            AccountType::Patient,
            AccountStatus::Active,
            LanguagePreference::English,
            app(PasswordHasher::class)->hash($password),
            1,
            true,
            false,
        ),
        $crypto['enc']->encrypt('phone', $phone->e164()),
        $crypto['hmac']->digest('phone_lookup', $phone->e164()),
        $cryptoVersion,
        $cryptoVersion,
        $now,
    );
    app(UserDirectory::class)->insertNationalId(
        app(IdentityGenerator::class)->next(),
        $userId,
        $crypto['enc']->encrypt('national_id', $nationalId->canonical()),
        $crypto['hmac']->digest('national_id_lookup', $nationalId->canonical()),
        $cryptoVersion,
        $cryptoVersion,
        $now,
    );

    return [
        'id' => $userId->value,
        'phone' => $phoneRaw,
        'national_id' => $nationalIdRaw,
        'password' => $password,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function isr014Idem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-isr014-'.$name.'-'.bin2hex(random_bytes(4))];
}

function isr014Login(mixed $test, string $phone, string $password)
{
    return $test->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone',
    ]);
}

/**
 * @return array<string, mixed>
 */
function isr014AuditMetadata(object $row): array
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

function isr014AuditBlob(object $row): string
{
    return json_encode(isr014AuditMetadata($row), JSON_THROW_ON_ERROR);
}

it('finds a v1 phone row after current hmac version becomes 2', function () {
    $identity = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);

    isr014Login($this, $identity['phone'], $identity['password'])->assertOk();
});

it('writes new phone ciphertext and hmac with version 2', function () {
    isr014UseCurrentVersion(2);
    $payload = [
        'name' => 'Synthetic Patient',
        'phone' => (new SyntheticEgyptianData)->mobileNumber(),
        'national_id' => (new SyntheticEgyptianData)->nationalId(),
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];

    $this->postJson('/api/v1/auth/registrations', $payload, isr014Idem('new-write'))->assertCreated();

    $user = isr014UserRowByPhone($payload['phone']);
    expect((int) $user->phone_key_version)->toBe(2)
        ->and((int) $user->phone_hmac_version)->toBe(2);

    $nid = DB::table('identity_national_ids')->where('user_id', $user->id)->first();
    expect($nid)->not->toBeNull()
        ->and((int) $nid->key_version)->toBe(2)
        ->and((int) $nid->hmac_key_version)->toBe(2);

    $otp = DB::table('otp_requests')
        ->where('subject_lookup_hmac', BinaryColumn::bind(BinaryColumn::asString($user->phone_lookup_hmac)))
        ->first();
    expect($otp)->not->toBeNull()
        ->and((int) $otp->key_version)->toBe(2);
});

it('finds a v1 national id under current version 2 and blocks a duplicate claim', function () {
    $identity = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);

    $found = app(UserDirectory::class)->nationalIdHmacsTaken(
        app(NationalIdProtector::class)->nationalIdLookupHmacs(
            app(NationalIdProtector::class)->nationalId($identity['national_id']),
        ),
    );
    expect($found)->toBeTrue();

    $otherPhone = (new SyntheticEgyptianData)->mobileNumber();
    $this->postJson('/api/v1/auth/registrations', [
        'name' => 'Other Patient',
        'phone' => $otherPhone,
        'national_id' => $identity['national_id'],
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ], isr014Idem('nid-dup'))->assertCreated();

    $protector = app(NationalIdProtector::class);
    expect(app(UserDirectory::class)->findByPhoneHmacs($protector->phoneLookupHmacs($protector->phone($otherPhone))))->toBeNull()
        ->and((int) DB::table('users')->where('id', $identity['id'])->count())->toBe(1)
        ->and((int) DB::table('identity_national_ids')->where('user_id', $identity['id'])->count())->toBe(1);
});

it('reads mixed v1 and v2 phone rows while new writes use v2', function () {
    $v1 = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    $v2 = isr014InsertIdentity(2);

    isr014Login($this, $v1['phone'], $v1['password'])->assertOk();
    isr014Login($this, $v2['phone'], $v2['password'])->assertOk();
    expect((int) DB::table('users')->where('id', $v2['id'])->value('phone_key_version'))->toBe(2);
});

it('issues and verifies otp against a stored v1 hmac after current becomes 2', function () {
    $identity = isr014InsertIdentity(1);
    $v1Hmac = BinaryColumn::asString(DB::table('users')->where('id', $identity['id'])->value('phone_lookup_hmac'));
    isr014UseCurrentVersion(2);

    $this->postJson('/api/v1/auth/recovery/start', [
        'phone' => $identity['phone'],
        'language' => 'en',
    ])->assertOk();

    $otp = DB::table('otp_requests')->where('purpose', 'recovery')->orderByDesc('created_at')->first();
    expect($otp)->not->toBeNull()
        ->and(BinaryColumn::asString($otp->subject_lookup_hmac))->toBe($v1Hmac);

    app(OutboxDispatcher::class)->dispatchBatch();
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    $this->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => $otp->id,
        'code' => $sms->lastCodeByPurpose['recovery'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone',
    ], isr014Idem('otp-v1'))->assertOk();
});

it('resumes an interrupted rotation and is idempotent on a second apply', function () {
    $first = isr014InsertIdentity(1);
    $second = isr014InsertIdentity(1);
    $ids = [$first['id'], $second['id']];
    isr014UseCurrentVersion(2);

    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 1])
        ->expectsOutputToContain('rewritten_phone=1')
        ->assertSuccessful();
    expect((int) DB::table('users')->whereIn('id', $ids)->where('phone_key_version', '<', 2)->count())->toBe(1);

    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 1])
        ->expectsOutputToContain('rewritten_phone=1')
        ->assertSuccessful();
    expect((int) DB::table('users')->whereIn('id', $ids)->where('phone_key_version', '<', 2)->count())->toBe(0);

    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 100])->assertSuccessful();
    expect((int) DB::table('identity_national_ids')->whereIn('user_id', $ids)->where('key_version', '<', 2)->count())->toBe(0);

    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 100])
        ->expectsOutputToContain('rewritten_phone=0')
        ->expectsOutputToContain('rewritten_national_id=0')
        ->assertSuccessful();
});

it('keeps login working on a partially migrated dataset', function () {
    $first = isr014InsertIdentity(1);
    $second = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);

    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 1])->assertSuccessful();

    isr014Login($this, $first['phone'], $first['password'])->assertOk();
    isr014Login($this, $second['phone'], $second['password'])->assertOk();
});

it('fails closed when the previous encryption key is missing while v1 rows remain', function () {
    isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    config(['identity.encryption.keys.1' => '']);
    isr014RebindCrypto();

    $this->artisan('identity:rotate-keys', ['--apply' => true])
        ->expectsOutputToContain('Identity key rotation failed closed')
        ->doesntExpectOutputToContain('+20')
        ->assertFailed();

    expect((int) DB::table('users')->value('phone_key_version'))->toBe(1);

    $this->artisan('identity:rotate-keys', ['--status' => true])
        ->expectsOutputToContain('retirement is not safe')
        ->assertSuccessful();
});

it('fails closed when decrypting rotated ciphertext with the wrong v2 key', function () {
    $identity = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 100])->assertSuccessful();

    config(['identity.encryption.keys.2' => 'wrong_identity_enc_v2_not_a_secret_val!!']);
    isr014RebindCrypto();

    $cipher = BinaryColumn::asString(DB::table('users')->where('id', $identity['id'])->value('phone_e164_encrypted'));
    expect(fn () => app(FieldEncryptor::class)->decrypt('phone', $cipher))
        ->toThrow(RuntimeException::class);
});

it('keeps mixed ciphertext readable after rolling current version back to 1', function () {
    $v1 = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    $v2 = isr014InsertIdentity(2);
    isr014UseCurrentVersion(1);

    isr014Login($this, $v1['phone'], $v1['password'])->assertOk();
    isr014Login($this, $v2['phone'], $v2['password'])->assertOk();
});

it('refuses v1 retirement while old-version otp ciphertext remains and allows it after prune', function () {
    $identity = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 100])->assertSuccessful();

    $crypto = isr014VersionedCrypto(1);
    $phone = app(NationalIdProtector::class)->phone($identity['phone']);
    $now = app(Clock::class)->now();
    DB::table('otp_requests')->insert([
        'id' => app(IdentityGenerator::class)->next()->value,
        'purpose' => 'recovery',
        'subject_lookup_hmac' => BinaryColumn::bind(app(NationalIdProtector::class)->phoneHmac($phone)),
        'code_hash' => BinaryColumn::bind(random_bytes(32)),
        'code_ciphertext' => BinaryColumn::bind($crypto['enc']->encrypt('otp_code', '123456')),
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => $now->modify('+5 minutes')->format('Y-m-d H:i:s.uP'),
        'consumed_at' => null,
        'invalidated_at' => null,
        'locale' => 'en',
        'destination_ciphertext' => BinaryColumn::bind($crypto['enc']->encrypt('phone', $phone->e164())),
        'key_version' => 1,
        'delivery_status' => 'pending',
        'created_at' => $now->format('Y-m-d H:i:s.uP'),
    ]);

    $this->artisan('identity:rotate-keys', ['--status' => true])
        ->expectsOutputToContain('live_otp_old_encryption=1')
        ->expectsOutputToContain('expire_do_not_reencrypt')
        ->expectsOutputToContain('retirement is not safe')
        ->assertSuccessful();

    DB::table('otp_requests')->update([
        'code_ciphertext' => null,
        'destination_ciphertext' => null,
    ]);

    $this->artisan('identity:rotate-keys', ['--status' => true])
        ->expectsOutputToContain('retirement is eligible')
        ->assertSuccessful();
});

it('requires --confirm for production apply and never prints secrets', function () {
    $identity = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    config(['app.env' => 'production']);

    $this->artisan('identity:rotate-keys', ['--apply' => true])
        ->expectsOutputToContain('Production rewrite requires --apply --confirm.')
        ->doesntExpectOutputToContain($identity['phone'])
        ->assertFailed();

    $this->artisan('identity:rotate-keys', ['--apply' => true, '--confirm' => true, '--batch' => 100])
        ->expectsOutputToContain('rewritten_phone=')
        ->doesntExpectOutputToContain($identity['phone'])
        ->doesntExpectOutputToContain($identity['national_id'])
        ->assertSuccessful();
});

it('records secret-free otp decrypt audits on delivery', function () {
    isr014UseCurrentVersion(1);
    $payload = [
        'name' => 'Synthetic Patient',
        'phone' => (new SyntheticEgyptianData)->mobileNumber(),
        'national_id' => (new SyntheticEgyptianData)->nationalId(),
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];
    $this->postJson('/api/v1/auth/registrations', $payload, isr014Idem('otp-audit'))->assertCreated();
    app(OutboxDispatcher::class)->dispatchBatch();

    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);
    $code = $sms->lastCodeByPurpose['registration'];

    $rows = DB::table('audit_events')->where('event_name', 'auth.sensitive_decrypt')->get();
    $reasons = $rows->map(fn ($row) => isr014AuditMetadata($row)['reason_code'] ?? null)->all();

    expect($reasons)->toContain('otp_delivery_code')
        ->and($reasons)->toContain('otp_delivery_destination');

    foreach ($rows as $row) {
        $blob = isr014AuditBlob($row);
        expect($blob)->not->toContain($payload['phone'])
            ->and($blob)->not->toContain($payload['national_id'])
            ->and($blob)->not->toContain($code)
            ->and($blob)->not->toContain((string) config('identity.encryption.keys.1'))
            ->and(isr014AuditMetadata($row)['decrypt_class'] ?? null)->toBe('internal_processing');
    }
});

it('records secret-free totp confirm decrypt audits', function () {
    $payload = [
        'name' => 'Synthetic Patient',
        'phone' => (new SyntheticEgyptianData)->mobileNumber(),
        'national_id' => (new SyntheticEgyptianData)->nationalId(),
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];
    $this->postJson('/api/v1/auth/registrations', $payload, isr014Idem('totp-audit'))->assertCreated();
    app(OutboxDispatcher::class)->dispatchBatch();
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);
    $verify = $this->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => DB::table('otp_requests')->value('id'),
        'code' => $sms->lastCodeByPurpose['registration'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone',
    ], isr014Idem('totp-audit-verify'));
    $verify->assertOk();
    DB::table('users')->update([
        'status' => 'active',
        'phone_verified_at' => now('UTC'),
    ]);
    $token = $verify->json('data.access_token');
    $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll', [], ['Authorization' => 'Bearer '.$token]);
    $enroll->assertOk();
    $query = [];
    parse_str((string) parse_url((string) $enroll->json('data.provisioning_uri'), PHP_URL_QUERY), $query);
    $secret = (string) ($query['secret'] ?? '');
    $code = app(TotpVerifier::class)->codeAt($secret, app(Clock::class)->now());
    $this->postJson('/api/v1/auth/mfa/totp/confirm', [
        'code' => $code,
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    $rows = DB::table('audit_events')
        ->where('event_name', 'auth.sensitive_decrypt')
        ->get()
        ->filter(fn ($row) => (isr014AuditMetadata($row)['reason_code'] ?? null) === 'totp_confirm');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        $blob = isr014AuditBlob($row);
        expect($blob)->not->toContain($secret)
            ->and($blob)->not->toContain($code)
            ->and($blob)->not->toContain($payload['phone']);
    }
});

it('records secret-free rotation decrypt audits', function () {
    $identity = isr014InsertIdentity(1);
    isr014UseCurrentVersion(2);
    $this->artisan('identity:rotate-keys', ['--apply' => true, '--batch' => 100])->assertSuccessful();

    $rows = DB::table('audit_events')
        ->where('event_name', 'auth.sensitive_decrypt')
        ->get()
        ->filter(fn ($row) => in_array(isr014AuditMetadata($row)['reason_code'] ?? null, [
            'phone_key_rotation',
            'national_id_key_rotation',
            'totp_key_rotation',
            'push_token_key_rotation',
        ], true));
    $reasons = $rows->map(fn ($row) => isr014AuditMetadata($row)['reason_code'] ?? null)->all();

    expect($reasons)->toContain('phone_key_rotation')
        ->and($reasons)->toContain('national_id_key_rotation');

    foreach ($rows as $row) {
        $blob = isr014AuditBlob($row);
        expect($blob)->not->toContain($identity['phone'])
            ->and($blob)->not->toContain($identity['national_id'])
            ->and($row->actor_type)->toBe('system');
    }
});
