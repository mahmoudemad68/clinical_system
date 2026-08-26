<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Persistence;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Infrastructure\Persistence\BinaryColumn;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

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

        $this->connection->selectOne("SELECT pg_advisory_xact_lock(hashtext('audit_events_chain'))");

        $previous = $this->connection->table('audit_events')
            ->orderByDesc('chain_sequence')
            ->value('row_hash');

        $sequence = (int) ($this->connection->selectOne("SELECT nextval('audit_events_chain_sequence_seq') AS n")->n ?? 0);
        $payload = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $previousBytes = $previous === null ? '' : BinaryColumn::asString($previous);
        $rowHash = hash('sha256', implode('|', [
            $previousBytes === '' ? '' : bin2hex($previousBytes),
            $id->value,
            $eventName,
            $objectType,
            $objectId->value,
            (string) ($actorId === null ? '' : $actorId->value),
            (string) ($actorType ?? ''),
            $payload,
            $occurred->format('Y-m-d\TH:i:s.u\Z'),
        ]), true);

        $this->connection->table('audit_events')->insert([
            'id' => $id->value,
            'event_name' => $eventName,
            'actor_id' => $actorId?->value,
            'actor_type' => $actorType,
            'object_type' => $objectType,
            'object_id' => $objectId->value,
            'metadata' => $payload,
            'previous_hash' => $previous === null ? null : BinaryColumn::bind($previousBytes),
            'row_hash' => BinaryColumn::bind($rowHash),
            'chain_sequence' => $sequence,
            'occurred_at' => $occurred->format('Y-m-d H:i:s.uP'),
        ]);

        return $id;
    }
}
