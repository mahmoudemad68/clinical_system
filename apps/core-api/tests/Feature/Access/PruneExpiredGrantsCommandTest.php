<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function grantPruneNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-28 12:00:00', new DateTimeZone('UTC'));
}

function insertPruneGrant(
    string $actorUserId,
    DateTimeImmutable $createdAt,
    ?DateTimeImmutable $revokedAt,
    ?DateTimeImmutable $validUntil,
): string {
    $ids = app(IdentityGenerator::class);
    $id = $ids->next()->value;

    DB::table('contextual_access_grants')->insert([
        'id' => $id,
        'actor_user_id' => $actorUserId,
        'capability' => Capabilities::CONTEXT_DELEGATE,
        'resource_type' => 'auth_session',
        'resource_id' => $ids->next()->value,
        'context_type' => 'self',
        'context_id' => $ids->next()->value,
        'valid_from' => null,
        'valid_until' => $validUntil?->format('Y-m-d H:i:s.uP'),
        'revoked_at' => $revokedAt?->format('Y-m-d H:i:s.uP'),
        'reason_code' => 'prune_fixture',
        'issued_by_type' => 'system',
        'issued_by_id' => $actorUserId,
        'version' => 1,
        'created_at' => $createdAt->format('Y-m-d H:i:s.uP'),
    ]);

    return $id;
}

it('deletes obsolete grants, keeps currently valid grants, isolates the other subject, and is idempotent', function () {
    $now = grantPruneNow();
    app()->instance(Clock::class, new FrozenClock($now));
    $userA = User::factory()->create(['status' => AccountStatus::Active->value]);
    $userB = User::factory()->create([
        'account_type' => AccountType::Patient->value,
        'status' => AccountStatus::Active->value,
    ]);
    $days = (int) config('identity.retention.revoked_grant_days', 90);
    $old = $now->modify(sprintf('-%d days', $days + 1));
    $purgeRevoked = insertPruneGrant((string) $userA->id, $old, $old, null);
    $purgeExpired = insertPruneGrant((string) $userA->id, $now->modify('-2 days'), null, $now->modify('-1 second'));
    $keepValidA = insertPruneGrant((string) $userA->id, $now, null, $now->modify('+1 day'));
    $keepRecentRevokedA = insertPruneGrant((string) $userA->id, $now, $now->modify('-1 day'), null);
    $keepB = insertPruneGrant((string) $userB->id, $now, null, $now->modify('+1 day'));

    $this->artisan('access:prune-expired')->assertSuccessful();

    $this->assertDatabaseMissing('contextual_access_grants', ['id' => $purgeRevoked]);
    $this->assertDatabaseMissing('contextual_access_grants', ['id' => $purgeExpired]);
    $this->assertDatabaseHas('contextual_access_grants', ['id' => $keepValidA]);
    $this->assertDatabaseHas('contextual_access_grants', ['id' => $keepRecentRevokedA]);
    $this->assertDatabaseHas('contextual_access_grants', ['id' => $keepB]);

    $this->artisan('access:prune-expired')->assertSuccessful();
    $this->assertDatabaseHas('contextual_access_grants', ['id' => $keepValidA]);
    $this->assertDatabaseHas('contextual_access_grants', ['id' => $keepB]);
});
