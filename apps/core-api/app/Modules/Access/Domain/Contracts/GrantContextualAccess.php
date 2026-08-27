<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\Contracts;

use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

interface GrantContextualAccess
{
    public function grant(
        ActorContext $initiator,
        Identifier $subjectUserId,
        string $capability,
        string $resourceType,
        Identifier $resourceId,
        string $contextType,
        Identifier $contextId,
        string $reasonCode,
        DateTimeImmutable $now,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ): Identifier;
}
