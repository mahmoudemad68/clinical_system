<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Append-only audit sink. No update or delete path exists.
 */
interface AppendAuditEvent
{
    /**
     * @param  array<string, bool|int|float|string|null>  $metadata  identifiers and reason codes only
     */
    public function append(
        TransactionContext $context,
        string $eventName,
        string $objectType,
        Identifier $objectId,
        array $metadata,
        ?Identifier $actorId = null,
        ?string $actorType = null,
    ): Identifier;
}
