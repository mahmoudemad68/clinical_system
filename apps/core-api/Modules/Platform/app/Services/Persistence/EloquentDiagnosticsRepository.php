<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Contracts\DiagnosticsRepository;
use Modules\Platform\Support\Identifier;
use RuntimeException;

/**
 * PostgreSQL implementation of the diagnostics port.
 *
 * Writes on the caller's connection so the row joins the active transaction.
 * The guard mirrors EloquentOutboxRecorder: a diagnostics row written outside a
 * transaction could not be atomic with its outbox row, which would defeat the
 * only thing this slice exists to demonstrate.
 */
final class EloquentDiagnosticsRepository implements DiagnosticsRepository
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function record(
        Identifier $diagnosticsId,
        string $label,
        int $echoDelayMs,
        Identifier $outboxEventId,
        Identifier $correlationId,
        DateTimeImmutable $recordedAt,
    ): void {
        if ($this->connection->transactionLevel() < 1) {
            throw new RuntimeException(
                'The diagnostics record must be written inside the transaction that also writes its '
                .'outbox row. Writing it outside would break the atomicity this slice exists to prove.',
            );
        }

        $this->connection->table('platform_diagnostics')->insert([
            'id' => $diagnosticsId->value,
            'label' => $label,
            'echo_delay_ms' => $echoDelayMs,
            'outbox_event_id' => $outboxEventId->value,
            'correlation_id' => $correlationId->value,
            'recorded_at' => $recordedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.uP'),
            'consumed_at' => null,
            'consumed_count' => 0,
        ]);
    }
}
