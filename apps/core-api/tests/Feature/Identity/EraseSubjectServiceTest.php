<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Contracts\GrantContextualAccess;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Services\AuthenticatePasswordService;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Enums\SubjectHoldingAction;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Services\ExportSubjectDataService;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\Phase01SubjectHoldings;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Exceptions\AuthenticationFailed;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\RateLimited;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{id: string, phone: string, hmac: string, cipher: string, password: string}
 */
function eraseSeedSubject(string $label): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $protector->phone($synthetic->mobileNumber());
    $password = 'password-factory-12';
    $now = now('UTC');
    $user = User::factory()->create([
        'name' => 'Subject '.$label,
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($phone)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
        'password_hash' => app(PasswordHasher::class)->hash($password),
        'status' => AccountStatus::Active->value,
        'account_type' => AccountType::Patient->value,
    ]);

    $ids = app(IdentityGenerator::class);
    $nid = $protector->nationalId($synthetic->nationalId());
    app(UserDirectory::class)->insertNationalId(
        $ids->next(),
        Identifier::fromTrusted((string) $user->id),
        $protector->encryptNationalId($nid),
        $protector->nationalIdHmac($nid),
        1,
        $now->toDateTimeImmutable(),
    );

    return [
        'id' => (string) $user->id,
        'phone' => $phone->e164(),
        'hmac' => $protector->phoneHmac($phone),
        'cipher' => BinaryColumn::asString(DB::table('users')->where('id', $user->id)->value('phone_e164_encrypted')),
        'password' => $password,
    ];
}

function eraseOperator(): ActorContext
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

function erasePatientActor(string $userId): ActorContext
{
    return new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Patient,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal1Password,
        1,
        null,
        null,
        [],
        Capabilities::AUTHENTICATED_SELF,
    );
}

function eraseSeedPhase01State(string $userId, string $hmac, string $label): void
{
    $ids = app(IdentityGenerator::class);
    $now = now('UTC');
    $protector = app(NationalIdProtector::class);
    $familyId = $ids->next()->value;
    $deviceId = $ids->next()->value;

    DB::table('user_devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'platform' => 'android',
        'device_label' => $label.'-device',
        'token_hash' => BinaryColumn::bind(random_bytes(32)),
        'refresh_token_hash' => BinaryColumn::bind(random_bytes(32)),
        'previous_refresh_token_hash' => BinaryColumn::bind(random_bytes(32)),
        'refresh_family_id' => $familyId,
        'refresh_generation' => 1,
        'credential_version' => 1,
        'last_seen_at' => $now,
        'expires_at' => $now->copy()->addHour(),
        'refresh_expires_at' => $now->copy()->addDay(),
        'revoked_at' => null,
        'revoked_reason' => null,
        'push_token_ciphertext' => BinaryColumn::bind($protector->encryptSecret('push_token', $label.'-push')),
        'refresh_replay_ciphertext' => BinaryColumn::bind($protector->encryptSecret('refresh_replay', '{"n":1}')),
        'refresh_replay_idempotency_hmac' => BinaryColumn::bind(random_bytes(32)),
        'refresh_replay_expires_at' => $now->copy()->addMinute(),
        'created_ip_prefix' => '203.0.113',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('auth_sessions')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'device_id' => $deviceId,
        'session_kind' => 'device',
        'session_hash' => BinaryColumn::bind(random_bytes(32)),
        'assurance_level' => 'aal1_password',
        'csrf_established' => false,
        'idle_expires_at' => null,
        'absolute_expires_at' => $now->copy()->addHour(),
        'credential_version' => 1,
        'revoked_at' => null,
        'revoked_reason' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('auth_refresh_consumptions')->insert([
        'family_id' => $familyId,
        'token_hash' => BinaryColumn::bind(hash('sha256', $label.'-refresh', true)),
        'generation' => 1,
        'consumed_at' => $now,
    ]);

    DB::table('otp_requests')->insert([
        'id' => $ids->next()->value,
        'purpose' => 'recovery',
        'subject_lookup_hmac' => BinaryColumn::bind($hmac),
        'code_hash' => BinaryColumn::bind(random_bytes(32)),
        'code_ciphertext' => BinaryColumn::bind($protector->encryptSecret('otp_code', '123456')),
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => $now->copy()->addMinutes(5),
        'consumed_at' => null,
        'invalidated_at' => null,
        'requested_ip_prefix' => '203.0.113',
        'device_fingerprint_hmac' => null,
        'provider_message_reference' => null,
        'locale' => 'en',
        'destination_ciphertext' => BinaryColumn::bind($protector->encryptPhone($protector->phone((new SyntheticEgyptianData)->mobileNumber()))),
        'key_version' => 1,
        'delivery_status' => 'pending',
        'created_at' => $now,
    ]);

    $factorId = $ids->next()->value;
    DB::table('mfa_factors')->insert([
        'id' => $factorId,
        'user_id' => $userId,
        'factor_type' => 'totp',
        'secret_ciphertext' => BinaryColumn::bind($protector->encryptSecret('totp_secret', str_repeat('A', 20))),
        'key_version' => 1,
        'last_used_counter' => null,
        'last_used_at' => null,
        'verified_at' => $now,
        'disabled_at' => null,
        'disabled_by' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('mfa_recovery_codes')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'factor_id' => $factorId,
        'code_hash' => BinaryColumn::bind(random_bytes(32)),
        'consumed_at' => null,
        'created_at' => $now,
    ]);

    DB::table('mfa_challenges')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => $label.'-mfa',
        'expires_at' => $now->copy()->addMinutes(5),
        'consumed_at' => null,
        'attempts' => 0,
        'created_at' => $now,
    ]);

    DB::table('recovery_requests')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'otp_id' => $ids->next()->value,
        'status' => 'cooling_off',
        'new_password_hash' => app(PasswordHasher::class)->hash('pending-recovery-12'),
        'cooling_off_until' => $now->copy()->addDay(),
        'applied_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('identity_profile_links')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'profile_type' => 'patient',
        'profile_id' => $ids->next()->value,
        'link_status' => 'active',
        'assurance_level' => 'aal1_password',
        'proof_reference' => null,
        'linked_at' => $now,
        'revoked_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('sessions')->insert([
        'id' => 'laravel-'.$label,
        'user_id' => $userId,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Pest',
        'payload' => 'session-payload-'.$label,
        'last_activity' => time(),
    ]);

    DB::table('outbox_events')->insert([
        'event_id' => $ids->next()->value,
        'event_type' => 'identity.status_changed',
        'schema_version' => 1,
        'aggregate_type' => 'User',
        'aggregate_id' => $userId,
        'occurred_at' => $now,
        'correlation_id' => $ids->next()->value,
        'classification' => 'personal',
        'payload' => json_encode(['user_id' => $userId, 'reason_code' => 'seed'], JSON_THROW_ON_ERROR),
        'status' => 'PENDING',
        'attempts' => 0,
        'available_at' => $now,
        'created_at' => $now,
    ]);

    app(RecordInboxNotification::class)->record('user', $userId, 'auth.recovery_cooling_off', ['ref' => $label]);
}

it('enumerates an explicit technical action for every Phase-01 holding', function () {
    $names = Phase01SubjectHoldings::holdingNames();

    expect($names)->toContain(
        'users',
        'identity_national_ids',
        'user_devices',
        'auth_sessions',
        'otp_requests',
        'audit_events',
        'firebase_fcm',
        'backup_artefacts',
        'audit_checkpoints',
    );

    $audit = collect(Phase01SubjectHoldings::plan())->firstWhere('holding', 'audit_events');
    expect($audit?->action)->toBe(SubjectHoldingAction::PreserveSecurityAudit);
});

it('erases subject A holdings, leaves subject B unchanged, and keeps the audit chain valid', function () {
    $subjectA = eraseSeedSubject('A');
    $subjectB = eraseSeedSubject('B');
    eraseSeedPhase01State($subjectA['id'], $subjectA['hmac'], 'a');
    eraseSeedPhase01State($subjectB['id'], $subjectB['hmac'], 'b');

    $now = app(Clock::class)->now();
    $ids = app(IdentityGenerator::class);
    $grants = app(GrantContextualAccess::class);
    $grantA = $grants->grant(
        eraseOperator(),
        Identifier::fromTrusted($subjectA['id']),
        Capabilities::CONTEXT_DELEGATE,
        'user',
        Identifier::fromTrusted($subjectA['id']),
        'self',
        $ids->next(),
        'erase_fixture_a',
        $now,
    );
    $grantB = $grants->grant(
        eraseOperator(),
        Identifier::fromTrusted($subjectB['id']),
        Capabilities::CONTEXT_DELEGATE,
        'user',
        Identifier::fromTrusted($subjectB['id']),
        'self',
        $ids->next(),
        'erase_fixture_b',
        $now,
    );

    $rates = app(AuthenticationRateLimiter::class);
    $rates->hitLogin($subjectA['hmac'], '203.0.113');
    $rates->hitLogin($subjectB['hmac'], '203.0.113');

    $beforeB = [
        'user' => (array) DB::table('users')->where('id', $subjectB['id'])->first(),
        'devices' => DB::table('user_devices')->where('user_id', $subjectB['id'])->count(),
        'sessions' => DB::table('auth_sessions')->where('user_id', $subjectB['id'])->whereNull('revoked_at')->count(),
        'otps' => DB::table('otp_requests')->where('subject_lookup_hmac', BinaryColumn::bind($subjectB['hmac']))->count(),
        'grants' => DB::table('contextual_access_grants')->where('id', $grantB->value)->count(),
        'nids' => DB::table('identity_national_ids')->where('user_id', $subjectB['id'])->count(),
        'notifications' => DB::table('notifications')->where('notifiable_id', $subjectB['id'])->count(),
        'laravel_sessions' => DB::table('sessions')->where('user_id', $subjectB['id'])->count(),
        'hmac' => BinaryColumn::asString(DB::table('users')->where('id', $subjectB['id'])->value('phone_lookup_hmac')),
        'cipher' => BinaryColumn::asString(DB::table('users')->where('id', $subjectB['id'])->value('phone_e164_encrypted')),
    ];

    $report = app(EraseSubjectService::class)->handle(
        eraseOperator(),
        Identifier::fromTrusted($subjectA['id']),
        'subject_erasure',
    );

    expect($report->alreadyErased)->toBeFalse()
        ->and($report->plan)->not->toBeEmpty()
        ->and(app(UserDirectory::class)->findByPhoneHmac($subjectA['hmac']))->toBeNull()
        ->and(DB::table('users')->where('id', $subjectA['id'])->value('status'))->toBe(AccountStatus::Closed->value)
        ->and(DB::table('users')->where('id', $subjectA['id'])->value('name'))->toBe('erased')
        ->and(BinaryColumn::asString(DB::table('users')->where('id', $subjectA['id'])->value('phone_lookup_hmac')))->not->toBe($subjectA['hmac'])
        ->and(BinaryColumn::asString(DB::table('users')->where('id', $subjectA['id'])->value('phone_e164_encrypted')))->not->toBe($subjectA['cipher'])
        ->and(DB::table('identity_national_ids')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('identity_profile_links')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('user_devices')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('auth_sessions')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('otp_requests')->where('subject_lookup_hmac', BinaryColumn::bind($subjectA['hmac']))->count())->toBe(0)
        ->and(DB::table('mfa_factors')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('mfa_recovery_codes')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('mfa_challenges')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('recovery_requests')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('contextual_access_grants')->where('id', $grantA->value)->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $subjectA['id'])->count())->toBe(0)
        ->and(DB::table('outbox_events')->where('aggregate_id', $subjectA['id'])->where('payload->reason_code', 'seed')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('event_name', 'identity.subject_erased')->where('object_id', $subjectA['id'])->count())->toBe(1)
        ->and(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();

    expect(fn () => app(AuthenticatePasswordService::class)->handle(
        $subjectA['phone'],
        $subjectA['password'],
        'patient_mobile',
        'android',
        'erased-device',
        '203.0.113',
    ))->toThrow(AuthenticationFailed::class);

    $afterB = DB::table('users')->where('id', $subjectB['id'])->first();
    expect((string) $afterB->status)->toBe(AccountStatus::Active->value)
        ->and((string) $afterB->name)->toBe('Subject B')
        ->and(BinaryColumn::asString($afterB->phone_lookup_hmac))->toBe($beforeB['hmac'])
        ->and(BinaryColumn::asString($afterB->phone_e164_encrypted))->toBe($beforeB['cipher'])
        ->and(DB::table('user_devices')->where('user_id', $subjectB['id'])->count())->toBe($beforeB['devices'])
        ->and(DB::table('auth_sessions')->where('user_id', $subjectB['id'])->whereNull('revoked_at')->count())->toBe($beforeB['sessions'])
        ->and(DB::table('otp_requests')->where('subject_lookup_hmac', BinaryColumn::bind($subjectB['hmac']))->count())->toBe($beforeB['otps'])
        ->and(DB::table('contextual_access_grants')->where('id', $grantB->value)->whereNull('revoked_at')->count())->toBe($beforeB['grants'])
        ->and(DB::table('identity_national_ids')->where('user_id', $subjectB['id'])->count())->toBe($beforeB['nids'])
        ->and(DB::table('notifications')->where('notifiable_id', $subjectB['id'])->count())->toBe($beforeB['notifications'])
        ->and(DB::table('sessions')->where('user_id', $subjectB['id'])->count())->toBe($beforeB['laravel_sessions'])
        ->and(app(UserDirectory::class)->findByPhoneHmac($subjectB['hmac']))->not->toBeNull();

    $issued = app(AuthenticatePasswordService::class)->handle(
        $subjectB['phone'],
        $subjectB['password'],
        'patient_mobile',
        'android',
        'b-device',
        '198.51.100',
    );
    expect($issued)->toBeArray();

    expect(fn () => $rates->hitLogin($subjectB['hmac'], '198.51.100'))->not->toThrow(RateLimited::class);

    $second = app(EraseSubjectService::class)->handle(
        eraseOperator(),
        Identifier::fromTrusted($subjectA['id']),
        'subject_erasure',
    );
    expect($second->alreadyErased)->toBeTrue()
        ->and(DB::table('users')->where('id', $subjectB['id'])->value('status'))->toBe(AccountStatus::Active->value)
        ->and(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();
});

it('rejects subject erasure from a patient actor', function () {
    $subject = eraseSeedSubject('deny');

    expect(fn () => app(EraseSubjectService::class)->handle(
        erasePatientActor($subject['id']),
        Identifier::fromTrusted($subject['id']),
        'subject_erasure',
    ))->toThrow(AuthorizationDenied::class);

    expect(DB::table('users')->where('id', $subject['id'])->value('status'))->toBe(AccountStatus::Active->value)
        ->and(DB::table('audit_events')->where('event_name', 'identity.subject_erased')->count())->toBe(0);
});

it('exports Phase-01 holdings without secrets and leaves subject B unmentioned', function () {
    $subjectA = eraseSeedSubject('export-a');
    $subjectB = eraseSeedSubject('export-b');
    eraseSeedPhase01State($subjectA['id'], $subjectA['hmac'], 'export-a');

    $export = app(ExportSubjectDataService::class)->handle(
        eraseOperator(),
        Identifier::fromTrusted($subjectA['id']),
    );
    $encoded = json_encode($export->toArray(), JSON_THROW_ON_ERROR);

    expect($export->subjectId)->toBe($subjectA['id'])
        ->and($encoded)->not->toContain($subjectA['password'])
        ->and($encoded)->not->toContain(bin2hex($subjectA['hmac']))
        ->and($encoded)->not->toContain($subjectB['id'])
        ->and($export->legalStatus['lawful_basis'])->toBe('OPEN_LEGAL_DECISION')
        ->and($export->operationalFollowThrough)->toContain('offline_client_vault_wipe');

    $otpHolding = collect($export->holdings)->firstWhere('holding', 'otp_requests');
    expect($otpHolding['count'] ?? 0)->toBe(1)
        ->and($otpHolding['action'])->toBe(SubjectHoldingAction::Delete->value);
});

it('clears subject A rate-limit keys without resetting subject B', function () {
    $subjectA = eraseSeedSubject('rate-a');
    $subjectB = eraseSeedSubject('rate-b');
    $rates = app(AuthenticationRateLimiter::class);
    $limit = (int) config('identity.rate_limits.login_per_subject_per_minute', 5);

    for ($i = 0; $i < $limit; $i++) {
        $rates->hitLogin($subjectA['hmac'], '203.0.113');
        $rates->hitLogin($subjectB['hmac'], '198.51.100');
    }

    expect(fn () => $rates->hitLogin($subjectA['hmac'], '203.0.113'))->toThrow(RateLimited::class);
    expect(fn () => $rates->hitLogin($subjectB['hmac'], '198.51.100'))->toThrow(RateLimited::class);

    app(EraseSubjectService::class)->handle(
        eraseOperator(),
        Identifier::fromTrusted($subjectA['id']),
        'subject_erasure',
    );

    expect(fn () => $rates->hitLogin($subjectA['hmac'], '203.0.113'))->not->toThrow(RateLimited::class);
    expect(fn () => $rates->hitLogin($subjectB['hmac'], '198.51.100'))->toThrow(RateLimited::class);
});
