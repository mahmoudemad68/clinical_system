<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function otpCipherNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-28 12:00:00', new DateTimeZone('UTC'));
}

/**
 * @return array{id: string, user_id: string, hmac: string}
 */
function insertOtpCipherRow(
    DateTimeImmutable $createdAt,
    DateTimeImmutable $expiresAt,
    string $hmac,
    string $userId,
    ?DateTimeImmutable $invalidatedAt = null,
    ?DateTimeImmutable $consumedAt = null,
): array {
    $ids = app(IdentityGenerator::class);
    $protector = app(NationalIdProtector::class);
    $otpId = $ids->next()->value;

    DB::table('otp_requests')->insert([
        'id' => $otpId,
        'purpose' => 'registration',
        'subject_lookup_hmac' => BinaryColumn::bind($hmac),
        'code_hash' => BinaryColumn::bind(random_bytes(32)),
        'code_ciphertext' => BinaryColumn::bind($protector->encryptSecret('otp_code', '123456')),
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
        'consumed_at' => $consumedAt?->format('Y-m-d H:i:s.uP'),
        'invalidated_at' => $invalidatedAt?->format('Y-m-d H:i:s.uP'),
        'requested_ip_prefix' => '203.0.113',
        'device_fingerprint_hmac' => null,
        'provider_message_reference' => null,
        'locale' => 'en',
        'destination_ciphertext' => BinaryColumn::bind($protector->encryptPhone($protector->phone((new SyntheticEgyptianData)->mobileNumber()))),
        'key_version' => 1,
        'delivery_status' => 'pending',
        'created_at' => $createdAt->format('Y-m-d H:i:s.uP'),
    ]);

    return ['id' => $otpId, 'user_id' => $userId, 'hmac' => $hmac];
}

function otpCipherHmacForUser(string $userId): string
{
    return BinaryColumn::asString(DB::table('users')->where('id', $userId)->value('phone_lookup_hmac'));
}

it('nulls code and destination ciphertext when an otp is consumed', function () {
    $now = otpCipherNow();
    $user = User::factory()->create();
    $hmac = otpCipherHmacForUser((string) $user->id);
    $otp = insertOtpCipherRow($now, $now->modify('+5 minutes'), $hmac, (string) $user->id);

    app(AuthDirectory::class)->consumeOtp(Identifier::fromTrusted($otp['id']), $now);

    $row = DB::table('otp_requests')->where('id', $otp['id'])->first();
    expect($row)->not->toBeNull()
        ->and($row->code_ciphertext)->toBeNull()
        ->and($row->destination_ciphertext)->toBeNull()
        ->and($row->consumed_at)->not->toBeNull();
});

it('nulls sensitive ciphertext when open otps are invalidated', function () {
    $now = otpCipherNow();
    $user = User::factory()->create();
    $hmac = otpCipherHmacForUser((string) $user->id);
    $otp = insertOtpCipherRow($now, $now->modify('+5 minutes'), $hmac, (string) $user->id);

    app(AuthDirectory::class)->invalidateOpenOtps($hmac, 'registration', $now);

    $row = DB::table('otp_requests')->where('id', $otp['id'])->first();
    expect($row)->not->toBeNull()
        ->and($row->code_ciphertext)->toBeNull()
        ->and($row->destination_ciphertext)->toBeNull()
        ->and($row->invalidated_at)->not->toBeNull();
});

it('nulls expired otp ciphertext before the engineering row ttl delete and preserves the other subject', function () {
    $now = otpCipherNow();
    app()->instance(Clock::class, new FrozenClock($now));
    $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
    $userB = User::factory()->create(['status' => AccountStatus::Active->value]);
    $hmacA = otpCipherHmacForUser((string) $userA->id);
    $hmacB = otpCipherHmacForUser((string) $userB->id);
    $expiredA = insertOtpCipherRow($now, $now->modify('-1 minute'), $hmacA, (string) $userA->id);
    $activeB = insertOtpCipherRow($now, $now->modify('+5 minutes'), $hmacB, (string) $userB->id);

    $this->artisan('auth:prune-expired')->assertSuccessful();

    $rowA = DB::table('otp_requests')->where('id', $expiredA['id'])->first();
    $rowB = DB::table('otp_requests')->where('id', $activeB['id'])->first();

    expect($rowA)->not->toBeNull()
        ->and($rowA->code_ciphertext)->toBeNull()
        ->and($rowA->destination_ciphertext)->toBeNull()
        ->and($rowA->invalidated_at)->not->toBeNull()
        ->and($rowB)->not->toBeNull()
        ->and($rowB->code_ciphertext)->not->toBeNull()
        ->and($rowB->destination_ciphertext)->not->toBeNull()
        ->and($rowB->invalidated_at)->toBeNull();

    $this->artisan('auth:prune-expired')->assertSuccessful();
    expect(DB::table('otp_requests')->where('id', $expiredA['id'])->exists())->toBeTrue()
        ->and(DB::table('otp_requests')->where('id', $activeB['id'])->exists())->toBeTrue();
});

it('deletes otp rows past the engineering ttl for subject A without deleting subject B', function () {
    $now = otpCipherNow();
    app()->instance(Clock::class, new FrozenClock($now));
    $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
    $userB = User::factory()->create(['status' => AccountStatus::Active->value]);
    $hmacA = otpCipherHmacForUser((string) $userA->id);
    $hmacB = otpCipherHmacForUser((string) $userB->id);
    $otpDays = (int) config('identity.retention.otp_row_days', 30);
    $old = $now->modify(sprintf('-%d days', $otpDays + 1));
    $purgeA = insertOtpCipherRow($old, $old->modify('-1 minute'), $hmacA, (string) $userA->id, $old);
    $liveB = insertOtpCipherRow($now, $now->modify('+5 minutes'), $hmacB, (string) $userB->id);

    $this->artisan('auth:prune-expired')->assertSuccessful();

    $this->assertDatabaseMissing('otp_requests', ['id' => $purgeA['id']]);
    $this->assertDatabaseHas('otp_requests', ['id' => $liveB['id']]);

    $this->artisan('auth:prune-expired')->assertSuccessful();
    $this->assertDatabaseHas('otp_requests', ['id' => $liveB['id']]);
});
