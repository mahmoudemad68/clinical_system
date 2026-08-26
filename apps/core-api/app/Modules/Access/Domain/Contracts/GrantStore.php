<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

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

    public function revoke(Identifier $id, DateTimeImmutable $now): void;

    /**
     * @return list<string>
     */
    public function activeCapabilities(Identifier $actorUserId, DateTimeImmutable $now): array;
}
