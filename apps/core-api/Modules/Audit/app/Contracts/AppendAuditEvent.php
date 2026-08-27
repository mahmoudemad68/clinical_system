<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Support\Identifier;

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
