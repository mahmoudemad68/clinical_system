<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\Persistence\AuditDatabaseIdentity;
use Modules\Audit\Services\Persistence\PostgresAuditStore;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Support\Identifier;

/**
 * Resolves the dedicated audit writer connection on first append so a wrong
 * pgsql_audit identity fails inside outbox consume rather than at container bind.
 */
final class DeferredAuditIdentityAppendAuditEvent implements AppendAuditEvent
{
    public function append(
        TransactionContext $context,
        string $eventName,
        string $objectType,
        Identifier $objectId,
        array $metadata,
        ?Identifier $actorId = null,
        ?string $actorType = null,
    ): Identifier {
        $store = new PostgresAuditStore(
            app(AuditDatabaseIdentity::class)->connection(),
            app(IdentityGenerator::class),
        );

        return $store->append($context, $eventName, $objectType, $objectId, $metadata, $actorId, $actorType);
    }
}
