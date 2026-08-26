<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\GrantContextualAccess;
use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Exceptions\DuplicateIdentity;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class GrantContextualAccessHandler implements GrantContextualAccess
{
    public function __construct(
        private readonly GrantStore $grants,
        private readonly IdentityGenerator $ids,
    ) {}

    public function grant(
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
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ): Identifier {
        $existing = $this->grants->findActive(
            $actorUserId,
            $capability,
            $resourceType,
            $resourceId,
            $contextType,
            $contextId,
        );

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->ids->next();

        try {
            $this->grants->insert(
                $id,
                $actorUserId,
                $capability,
                $resourceType,
                $resourceId,
                $contextType,
                $contextId,
                $reasonCode,
                $issuedByType,
                $issuedById,
                $now,
                $validFrom,
                $validUntil,
            );
        } catch (DuplicateIdentity) {
            $winner = $this->grants->findActive(
                $actorUserId,
                $capability,
                $resourceType,
                $resourceId,
                $contextType,
                $contextId,
            );

            if ($winner instanceof Identifier) {
                return $winner;
            }

            throw new DuplicateIdentity;
        }

        return $id;
    }
}
