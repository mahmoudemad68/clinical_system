<?php

declare(strict_types=1);

namespace Modules\Access\Contracts;

use DateTimeImmutable;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Support\Identifier;

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
