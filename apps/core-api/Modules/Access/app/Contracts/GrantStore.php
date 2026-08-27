<?php

declare(strict_types=1);

namespace Modules\Access\Contracts;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;

interface GrantStore
{
    public function findActive(
        Identifier $actorUserId,
        string $capability,
        string $resourceType,
        Identifier $resourceId,
        string $contextType,
        Identifier $contextId,
    ): ?Identifier;

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
    ): void;

    /**
     * @return array{id: string, actor_user_id: string}|null
     */
    public function find(Identifier $id): ?array;

    public function revoke(Identifier $id, DateTimeImmutable $now): void;

    /**
     * @return list<string>
     */
    public function activeCapabilities(Identifier $actorUserId, DateTimeImmutable $now): array;
}
