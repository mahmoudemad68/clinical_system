<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\Identifier;

/**
 * Test decorator that fails the first matching audit append, then forwards.
 * Used to simulate a post-copy DB/audit crash during canonical sealing.
 */
final class FailOnceAppendAuditEvent implements AppendAuditEvent
{
    public int $failures = 0;

    private bool $armed = true;

    public function __construct(
        private readonly AppendAuditEvent $inner,
        private readonly string $eventName,
    ) {}

    /**
     * @param  array<string, bool|int|float|string|null>  $metadata
     */
    public function append(
        TransactionContext $context,
        string $eventName,
        string $objectType,
        Identifier $objectId,
        array $metadata,
        ?Identifier $actorId = null,
        ?string $actorType = null,
    ): Identifier {
        if ($this->armed && $eventName === $this->eventName) {
            $this->armed = false;
            $this->failures++;

            throw new TransientProviderFailure('simulated completion audit failure');
        }

        return $this->inner->append($context, $eventName, $objectType, $objectId, $metadata, $actorId, $actorType);
    }
}
