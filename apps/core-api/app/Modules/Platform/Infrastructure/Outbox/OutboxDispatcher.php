<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Outbox;

use App\Modules\Platform\Application\Outbox\OutboxConsumer;
use App\Modules\Platform\Application\Outbox\RetryPolicy;
use App\Modules\Platform\Domain\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims outbox rows and drives their consumers (ADR 0004).
 *
 * The claim uses `FOR UPDATE SKIP LOCKED`, which is what lets several workers
 * run concurrently without coordinating: each takes a disjoint set of rows and
 * no worker waits behind another's lock.
 *
 * A lease is written alongside the claim. If a worker dies mid-processing its
 * rows stay CLAIMED with an expired lease, and recoverExpiredLeases() returns
 * them to the pool. Without a lease those rows would be stuck forever, which is
 * the failure mode the kill-a-worker system test exists to catch.
 *
 * Ordering note: rows are claimed by available_at, and nothing here guarantees
 * global ordering between events. Consumers must not depend on receiving two
 * events in a particular order. If an invariant needs ordering, it belongs
 * inside the originating transaction instead.
 */
final class OutboxDispatcher
{
    /** @var array<string, OutboxConsumer> */
    private array $consumers = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
        private readonly RetryPolicy $retryPolicy,
        private readonly LoggerInterface $logger,
        private readonly string $workerId,
        private readonly int $batchSize = 100,
        private readonly int $leaseSeconds = 60,
    ) {
    }

    public function register(OutboxConsumer $consumer): void
    {
        $this->consumers[$consumer->handles()] = $consumer;
    }

    /**
     * Claim and process one batch. Returns the number of rows processed.
     */
    public function dispatchBatch(): int
    {
        $claimed = $this->claimBatch();

        foreach ($claimed as $row) {
            $this->process($row);
        }

        return count($claimed);
    }

    /**
     * Atomically claim a batch of due rows.
     *
     * The UPDATE ... FROM (SELECT ... FOR UPDATE SKIP LOCKED) form performs the
     * select and the claim in one statement, so there is no window in which two
     * workers both see a row before either marks it claimed.
     *
     * @return list<object>
     */
    private function claimBatch(): array
    {
        $now = $this->clock->now();
        $leaseUntil = $now->modify(sprintf('+%d seconds', $this->leaseSeconds));

        $rows = $this->connection->select(
            'UPDATE outbox_events AS o '
            . "SET status = 'CLAIMED', claimed_at = ?, claimed_by = ?, lease_expires_at = ? "
            . 'FROM ( '
            . '  SELECT event_id FROM outbox_events '
            . "  WHERE status IN ('PENDING', 'FAILED') AND available_at <= ? "
            . '  ORDER BY available_at, event_id LIMIT ? FOR UPDATE SKIP LOCKED '
            . ') AS due '
            . 'WHERE o.event_id = due.event_id '
            . 'RETURNING o.event_id, o.event_type, o.schema_version, o.payload, o.attempts',
            [
                $this->format($now),
                $this->workerId,
                $this->format($leaseUntil),
                $this->format($now),
                $this->batchSize,
            ],
        );

        return array_values($rows);
    }

    private function process(object $row): void
    {
        $eventId = (string) $row->event_id;
        $eventType = (string) $row->event_type;
        $version = (int) $row->schema_version;
        $attempts = (int) $row->attempts;

        $consumer = $this->consumers[$eventType] ?? null;

        if ($consumer === null) {
            // No consumer is not a transient condition. Retrying forever would
            // hide a deployment mistake; dead-letter makes it visible.
            $this->deadLetter($eventId, 'no_consumer_registered');

            return;
        }

        if (!in_array($version, $consumer->supportedVersions(), true)) {
            // Reject safely rather than guess at an unknown payload shape.
            $this->deadLetter($eventId, 'unsupported_schema_version');

            return;
        }

        $payload = json_decode((string) $row->payload, true);

        if (!is_array($payload)) {
            $this->deadLetter($eventId, 'malformed_payload');

            return;
        }

        try {
            $consumer->consume($eventId, $payload);
            $this->markProcessed($eventId);
        } catch (Throwable $e) {
            $this->handleFailure($eventId, $attempts, $e);
        }
    }

    private function handleFailure(string $eventId, int $attempts, Throwable $e): void
    {
        $attempts++;
        $errorClass = $this->safeErrorClass($e);

        if (!$this->retryPolicy->shouldRetry($attempts)) {
            $this->deadLetter($eventId, $errorClass, $attempts);

            // Operator-visible. An exhausted event is never silently discarded.
            $this->logger->error('outbox.dead_letter', [
                'event_id' => $eventId,
                'attempts' => $attempts,
                'error_class' => $errorClass,
            ]);

            return;
        }

        $delay = $this->retryPolicy->delayFor($attempts);
        $nextAttempt = $this->clock->now()->modify(sprintf('+%d seconds', $delay));

        $this->connection->table('outbox_events')
            ->where('event_id', $eventId)
            ->update([
                'status' => 'FAILED',
                'attempts' => $attempts,
                'available_at' => $this->format($nextAttempt),
                'last_error_class' => $errorClass,
                'claimed_at' => null,
                'claimed_by' => null,
                'lease_expires_at' => null,
            ]);

        $this->logger->warning('outbox.retry_scheduled', [
            'event_id' => $eventId,
            'attempts' => $attempts,
            'delay_seconds' => $delay,
            'error_class' => $errorClass,
        ]);
    }

    private function markProcessed(string $eventId): void
    {
        $this->connection->table('outbox_events')
            ->where('event_id', $eventId)
            ->update([
                'status' => 'PROCESSED',
                'processed_at' => $this->format($this->clock->now()),
                'claimed_at' => null,
                'claimed_by' => null,
                'lease_expires_at' => null,
            ]);
    }

    private function deadLetter(string $eventId, string $errorClass, ?int $attempts = null): void
    {
        $update = [
            'status' => 'DEAD_LETTER',
            'last_error_class' => $errorClass,
            'claimed_at' => null,
            'claimed_by' => null,
            'lease_expires_at' => null,
        ];

        if ($attempts !== null) {
            $update['attempts'] = $attempts;
        }

        $this->connection->table('outbox_events')->where('event_id', $eventId)->update($update);
    }

    /**
     * Return rows whose claiming worker died to the pool.
     *
     * @return int number of rows recovered
     */
    public function recoverExpiredLeases(): int
    {
        return $this->connection->table('outbox_events')
            ->where('status', 'CLAIMED')
            ->where('lease_expires_at', '<', $this->format($this->clock->now()))
            ->update([
                'status' => 'PENDING',
                'claimed_at' => null,
                'claimed_by' => null,
                'lease_expires_at' => null,
            ]);
    }

    /**
     * A stable, non-sensitive label for the failure.
     *
     * Never the exception message: provider errors routinely quote request
     * payloads, and those can carry clinical content into an operator log.
     */
    private function safeErrorClass(Throwable $e): string
    {
        $parts = explode('\\', $e::class);
        $short = end($parts) ?: 'unknown';

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    private function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
