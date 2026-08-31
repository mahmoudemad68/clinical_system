<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Support\Identifier;

final class PostgresAuditStore implements AppendAuditEvent
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IdentityGenerator $identities,
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
        $id = $this->identities->next();
        $occurred = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occurredAt = $occurred->format('Y-m-d H:i:s.uP');
        $payload = self::canonicalMetadata($metadata);

        $this->connection->statement(
            'SELECT clinic_append_audit_event(?, ?, ?, ?, ?, ?, ?::jsonb, ?::timestamptz)',
            [
                $id->value,
                $eventName,
                $actorId?->value,
                $actorType,
                $objectType,
                $objectId->value,
                $payload,
                $occurredAt,
            ],
        );

        return $id;
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $metadata
     */
    public static function canonicalMetadata(array $metadata): string
    {
        ksort($metadata);

        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
