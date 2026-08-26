<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure\Persistence;

use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Platform\Domain\Exceptions\DuplicateIdentity;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use stdClass;

final class PostgresGrantStore implements GrantStore
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function findActive(
        Identifier $actorUserId,
        string $capability,
        string $resourceType,
        Identifier $resourceId,
        string $contextType,
        Identifier $contextId,
    ): ?Identifier {
        $row = $this->connection->table('contextual_access_grants')
            ->where('actor_user_id', $actorUserId->value)
            ->where('capability', $capability)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId->value)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId->value)
            ->whereNull('revoked_at')
            ->first();

        return $row instanceof stdClass ? Identifier::fromTrusted((string) $row->id) : null;
    }

    public function insert(
        Identifier $id,
        Identifier $actorUserId,
        string $capability,
        string $resourceType,
        Identifier $resourceId,
        string $contextType,
        Identifier $contextId,
        string $reasonCode,
        string $issuedByType,
        Identifier $issuedById,
        DateTimeImmutable $now,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
    ): void {
        try {
            $this->connection->table('contextual_access_grants')->insert([
                'id' => $id->value,
                'actor_user_id' => $actorUserId->value,
                'capability' => $capability,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId->value,
                'context_type' => $contextType,
                'context_id' => $contextId->value,
                'valid_from' => $validFrom?->format('Y-m-d H:i:s.uP'),
                'valid_until' => $validUntil?->format('Y-m-d H:i:s.uP'),
                'revoked_at' => null,
                'reason_code' => $reasonCode,
                'issued_by_type' => $issuedByType,
                'issued_by_id' => $issuedById->value,
                'version' => 1,
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    public function revoke(Identifier $id, DateTimeImmutable $now): void
    {
        $this->connection->table('contextual_access_grants')->where('id', $id->value)->update([
            'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function activeCapabilities(Identifier $actorUserId, DateTimeImmutable $now): array
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $rows = $this->connection->table('contextual_access_grants')
            ->where('actor_user_id', $actorUserId->value)
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($stamp): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $stamp);
            })
            ->where(function (Builder $query) use ($stamp): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $stamp);
            })
            ->pluck('capability');

        return array_values(array_unique($rows->all()));
    }
}
