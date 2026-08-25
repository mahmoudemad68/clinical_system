<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\OutboxRecorder;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Writes outbox rows on the caller's connection, inside the caller's transaction.
 *
 * The guard below is the whole point of the class: recording outside a
 * transaction produces a row that will be published for a change that may never
 * commit, which is the dual-write failure the outbox exists to remove. It is
 * cheaper to fail here than to debug a phantom notification later.
 */
final class EloquentOutboxRecorder implements OutboxRecorder
{
    /**
     * Hard bound on a serialized payload.
     *
     * Events carry identifiers and a few non-sensitive facts. A payload beyond
     * this size is almost always someone attaching a whole record, which the
     * event contract forbids, so the bound doubles as a design check.
     */
    private const MAX_PAYLOAD_BYTES = 16_384;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IdentityGenerator $identities,
    ) {}

    public function record(DomainEvent $event, TransactionContext $context): Identifier
    {
        return $this->recordAll([$event], $context)[0];
    }

    public function recordAll(array $events, TransactionContext $context): array
    {
        if ($events === []) {
            return [];
        }

        if ($this->connection->transactionLevel() < 1) {
            throw new RuntimeException(
                'Outbox rows must be recorded inside the transaction that makes the state change. '
                .'Recording outside one would publish an effect for a change that may never commit.',
            );
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP');
        $rows = [];
        $ids = [];

        foreach ($events as $event) {
            $eventId = $this->identities->next();
            $ids[] = $eventId;

            $payload = json_encode($event->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                throw new RuntimeException(sprintf(
                    'Event "%s" payload is %d bytes, above the %d byte bound. Events carry identifiers and '
                    .'minimal facts; a consumer needing more re-reads it from the owning module.',
                    $event->eventType(),
                    strlen($payload),
                    self::MAX_PAYLOAD_BYTES,
                ));
            }

            $rows[] = [
                'event_id' => $eventId->value,
                'event_type' => $event->eventType(),
                'schema_version' => $event->schemaVersion(),
                'aggregate_type' => $event->aggregateType(),
                'aggregate_id' => $event->aggregateId()->value,
                'occurred_at' => $event->occurredAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                'correlation_id' => $context->correlationId()->value,
                'causation_id' => null,
                'classification' => $event->classification()->value,
                'payload' => $payload,
                'status' => 'PENDING',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
            ];
        }

        $this->connection->table('outbox_events')->insert($rows);

        return $ids;
    }
}
