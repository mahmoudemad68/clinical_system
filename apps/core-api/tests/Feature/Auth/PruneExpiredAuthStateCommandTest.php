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
});
