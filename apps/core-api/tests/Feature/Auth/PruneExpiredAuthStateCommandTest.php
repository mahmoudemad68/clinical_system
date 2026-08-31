<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pruneNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-28 12:00:00', new DateTimeZone('UTC'));
}

function bindPruneClock(DateTimeImmutable $now): void
{
    app()->instance(Clock::class, new FrozenClock($now));
}

/**
 * @return array{id: string, user_id: string}
 */
function insertPruneOtp(DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt, ?DateTimeImmutable $invalidatedAt = null): array
{
    $ids = app(IdentityGenerator::class);
    $protector = app(NationalIdProtector::class);
    $phone = $protector->phone((new SyntheticEgyptianData)->mobileNumber());
    $user = User::factory()->create();
    $otpId = $ids->next()->value;

    DB::table('otp_requests')->insert([
        'id' => $otpId,
        'purpose' => 'registration',
        'subject_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
        'code_hash' => BinaryColumn::bind(random_bytes(32)),
        'code_ciphertext' => BinaryColumn::bind($protector->encryptSecret('otp_code', '123456')),
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
        'consumed_at' => null,
        'invalidated_at' => $invalidatedAt?->format('Y-m-d H:i:s.uP'),
        'requested_ip_prefix' => '203.0.113',
        'device_fingerprint_hmac' => null,
        'provider_message_reference' => null,
        'locale' => 'en',
        'destination_ciphertext' => BinaryColumn::bind($protector->encryptPhone($phone)),
        'key_version' => 1,
        'delivery_status' => 'pending',
        'created_at' => $createdAt->format('Y-m-d H:i:s.uP'),
    ]);

    return ['id' => $otpId, 'user_id' => (string) $user->id];
}

function insertPruneSession(
    string $userId,
    DateTimeImmutable $now,
    DateTimeImmutable $absoluteExpiresAt,
    ?DateTimeImmutable $revokedAt,
): string {
    $id = app(IdentityGenerator::class)->next()->value;

    DB::table('auth_sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'device_id' => null,
        'session_kind' => 'device',
        'session_hash' => BinaryColumn::bind(random_bytes(32)),
        'assurance_level' => 'aal1_password',
        'csrf_established' => false,
        'idle_expires_at' => null,
        'absolute_expires_at' => $absoluteExpiresAt->format('Y-m-d H:i:s.uP'),
        'credential_version' => 1,
        'revoked_at' => $revokedAt?->format('Y-m-d H:i:s.uP'),
        'revoked_reason' => $revokedAt === null ? null : 'operator',
        'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
        'created_at' => $now->format('Y-m-d H:i:s.uP'),
        'updated_at' => $now->format('Y-m-d H:i:s.uP'),
    ]);

    return $id;
}

function insertPruneDevice(
    string $userId,
    DateTimeImmutable $now,
    ?DateTimeImmutable $revokedAt,
): string {
    $id = app(IdentityGenerator::class)->next()->value;

    DB::table('user_devices')->insert([
        'id' => $id,
        'user_id' => $userId,
        'platform' => 'android',
        'device_label' => 'prune-device',
        'token_hash' => $revokedAt === null ? BinaryColumn::bind(random_bytes(32)) : null,
        'refresh_token_hash' => $revokedAt === null ? BinaryColumn::bind(random_bytes(32)) : null,
        'previous_refresh_token_hash' => null,
        'refresh_family_id' => app(IdentityGenerator::class)->next()->value,
        'refresh_generation' => 1,
        'credential_version' => 1,
        'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
        'expires_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s.uP'),
        'refresh_expires_at' => $now->modify('+1 day')->format('Y-m-d H:i:s.uP'),
        'revoked_at' => $revokedAt?->format('Y-m-d H:i:s.uP'),
        'revoked_reason' => $revokedAt === null ? null : 'operator',
        'push_token_ciphertext' => $revokedAt === null ? BinaryColumn::bind(random_bytes(32)) : null,
        'created_ip_prefix' => '203.0.113',
        'created_at' => $now->format('Y-m-d H:i:s.uP'),
        'updated_at' => $now->format('Y-m-d H:i:s.uP'),
    ]);

    return $id;
}

function insertPruneRecovery(
    string $userId,
    DateTimeImmutable $now,
    string $status,
    DateTimeImmutable $updatedAt,
): string {
    $id = app(IdentityGenerator::class)->next()->value;

    DB::table('recovery_requests')->insert([
        'id' => $id,
        'user_id' => $userId,
        'otp_id' => app(IdentityGenerator::class)->next()->value,
        'status' => $status,
        'new_password_hash' => 'argon2id-placeholder-hash',
        'cooling_off_until' => $status === 'cooling_off' ? $now->modify('+1 day')->format('Y-m-d H:i:s.uP') : null,
        'applied_at' => $status === 'applied' ? $updatedAt->format('Y-m-d H:i:s.uP') : null,
        'created_at' => $updatedAt->format('Y-m-d H:i:s.uP'),
        'updated_at' => $updatedAt->format('Y-m-d H:i:s.uP'),
    ]);

    return $id;
}

function insertPruneConsumption(string $familyId, DateTimeImmutable $consumedAt): void
{
    DB::table('auth_refresh_consumptions')->insert([
        'family_id' => $familyId,
        'token_hash' => BinaryColumn::bind(random_bytes(32)),
        'generation' => 1,
        'consumed_at' => $consumedAt->format('Y-m-d H:i:s.uP'),
    ]);
}

describe('expired OTP ciphertext', function () {
    it('nulls code and destination ciphertext on expired open otp rows without deleting them', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $otp = insertPruneOtp($now, $now->modify('-1 minute'));

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $row = DB::table('otp_requests')->where('id', $otp['id'])->first();

        expect($row)->not->toBeNull()
            ->and($row->code_ciphertext)->toBeNull()
            ->and($row->destination_ciphertext)->toBeNull()
            ->and($row->invalidated_at)->not->toBeNull();
    });

    it('deletes otp rows past the engineering ttl after ciphertext is already null', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $otpDays = (int) config('identity.retention.otp_row_days', 30);
        $invalidatedAt = $now->modify(sprintf('-%d days', $otpDays + 1));
        $otp = insertPruneOtp($invalidatedAt, $invalidatedAt->modify('-1 minute'), $invalidatedAt);

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $this->assertDatabaseMissing('otp_requests', ['id' => $otp['id']]);
    });
});

describe('expired auth sessions', function () {
    it('marks expired sessions revoked without deleting them before the engineering ttl', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $user = User::factory()->create(['status' => AccountStatus::Active->value]);
        $sessionId = insertPruneSession((string) $user->id, $now, $now->modify('-1 second'), null);

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $row = DB::table('auth_sessions')->where('id', $sessionId)->first();

        expect($row)->not->toBeNull()
            ->and($row->revoked_at)->not->toBeNull()
            ->and($row->revoked_reason)->toBe('expired');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => AccountStatus::Active->value,
        ]);
    });

    it('deletes sessions whose revoked_at is older than the engineering ttl', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $user = User::factory()->create(['status' => AccountStatus::Active->value]);
        $sessionDays = (int) config('identity.retention.revoked_session_days', 90);
        $revokedAt = $now->modify(sprintf('-%d days', $sessionDays + 1));
        $keepId = insertPruneSession((string) $user->id, $now, $now->modify('+1 hour'), $now->modify('-1 day'));
        $purgeId = insertPruneSession((string) $user->id, $revokedAt, $revokedAt->modify('+1 hour'), $revokedAt);

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $this->assertDatabaseMissing('auth_sessions', ['id' => $purgeId]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $keepId]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => AccountStatus::Active->value,
        ]);
    });

    it('preserves an active session, a recently revoked session, and the other user, and is idempotent', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
        $userB = User::factory()->create(['status' => AccountStatus::Active->value]);
        $sessionDays = (int) config('identity.retention.revoked_session_days', 90);
        $expiredA = insertPruneSession((string) $userA->id, $now, $now->modify('-1 second'), null);
        $oldRevokedA = insertPruneSession(
            (string) $userA->id,
            $now->modify(sprintf('-%d days', $sessionDays + 2)),
            $now->modify(sprintf('-%d days', $sessionDays + 1)),
            $now->modify(sprintf('-%d days', $sessionDays + 1)),
        );
        $recentRevokedA = insertPruneSession((string) $userA->id, $now, $now->modify('+1 hour'), $now->modify('-1 day'));
        $activeB = insertPruneSession((string) $userB->id, $now, $now->modify('+1 hour'), null);

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $expiredRow = DB::table('auth_sessions')->where('id', $expiredA)->first();
        expect($expiredRow)->not->toBeNull()
            ->and($expiredRow->revoked_reason)->toBe('expired');
        $this->assertDatabaseMissing('auth_sessions', ['id' => $oldRevokedA]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $recentRevokedA]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $activeB]);
        expect(DB::table('auth_sessions')->where('id', $activeB)->value('revoked_at'))->toBeNull();

        $this->artisan('auth:prune-expired')->assertSuccessful();
        $this->assertDatabaseMissing('auth_sessions', ['id' => $oldRevokedA]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $recentRevokedA]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $activeB]);
        expect(DB::table('auth_sessions')->where('id', $expiredA)->value('revoked_reason'))->toBe('expired');
    });
});

describe('obsolete recovery requests', function () {
    it('deletes terminal recovery rows past the engineering ttl, keeps live rows, and does not touch the other subject', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
        $userB = User::factory()->create(['status' => AccountStatus::Active->value]);
        $days = (int) config('identity.retention.recovery_request_days', 90);
        $old = $now->modify(sprintf('-%d days', $days + 1));
        $purgeId = insertPruneRecovery((string) $userA->id, $now, 'applied', $old);
        $coolingA = insertPruneRecovery((string) $userA->id, $now, 'cooling_off', $old);
        $recentAppliedA = insertPruneRecovery((string) $userA->id, $now, 'applied', $now->modify('-1 day'));
        $appliedB = insertPruneRecovery((string) $userB->id, $now, 'applied', $now->modify('-1 day'));

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $this->assertDatabaseMissing('recovery_requests', ['id' => $purgeId]);
        $this->assertDatabaseHas('recovery_requests', ['id' => $coolingA]);
        $this->assertDatabaseHas('recovery_requests', ['id' => $recentAppliedA]);
        $this->assertDatabaseHas('recovery_requests', ['id' => $appliedB]);

        $this->artisan('auth:prune-expired')->assertSuccessful();
        $this->assertDatabaseMissing('recovery_requests', ['id' => $purgeId]);
        $this->assertDatabaseHas('recovery_requests', ['id' => $coolingA]);
        $this->assertDatabaseHas('recovery_requests', ['id' => $appliedB]);
    });
});

describe('obsolete user devices', function () {
    it('deletes old revoked devices, keeps current devices, and isolates the other subject', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
        $userB = User::factory()->create(['status' => AccountStatus::Active->value]);
        $days = (int) config('identity.retention.revoked_device_days', 90);
        $old = $now->modify(sprintf('-%d days', $days + 1));
        $purgeId = insertPruneDevice((string) $userA->id, $old, $old);
        $recentRevokedA = insertPruneDevice((string) $userA->id, $now, $now->modify('-1 day'));
        $activeA = insertPruneDevice((string) $userA->id, $now, null);
        $activeB = insertPruneDevice((string) $userB->id, $now, null);

        $this->artisan('auth:prune-expired')->assertSuccessful();

        $this->assertDatabaseMissing('user_devices', ['id' => $purgeId]);
        $this->assertDatabaseHas('user_devices', ['id' => $recentRevokedA]);
        $this->assertDatabaseHas('user_devices', ['id' => $activeA]);
        $this->assertDatabaseHas('user_devices', ['id' => $activeB]);
        expect(DB::table('user_devices')->where('id', $activeA)->value('revoked_at'))->toBeNull();

        $this->artisan('auth:prune-expired')->assertSuccessful();
        $this->assertDatabaseMissing('user_devices', ['id' => $purgeId]);
        $this->assertDatabaseHas('user_devices', ['id' => $activeB]);
    });
});

describe('obsolete refresh consumptions', function () {
    it('deletes old refresh consumptions, keeps recent ones, and isolates the other family', function () {
        $now = pruneNow();
        bindPruneClock($now);
        $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
        $userB = User::factory()->create(['status' => AccountStatus::Active->value]);
        $familyA = insertPruneDevice((string) $userA->id, $now, null);
        $familyB = insertPruneDevice((string) $userB->id, $now, null);
        $familyIdA = (string) DB::table('user_devices')->where('id', $familyA)->value('refresh_family_id');
        $familyIdB = (string) DB::table('user_devices')->where('id', $familyB)->value('refresh_family_id');
        $days = (int) config('identity.retention.refresh_consumption_days', 90);
        insertPruneConsumption($familyIdA, $now->modify(sprintf('-%d days', $days + 1)));
        insertPruneConsumption($familyIdB, $now->modify('-1 day'));

        $this->artisan('auth:prune-expired')->assertSuccessful();

        expect(DB::table('auth_refresh_consumptions')->where('family_id', $familyIdA)->count())->toBe(0)
            ->and(DB::table('auth_refresh_consumptions')->where('family_id', $familyIdB)->count())->toBe(1);

        $this->artisan('auth:prune-expired')->assertSuccessful();
        expect(DB::table('auth_refresh_consumptions')->where('family_id', $familyIdB)->count())->toBe(1);
    });
});
