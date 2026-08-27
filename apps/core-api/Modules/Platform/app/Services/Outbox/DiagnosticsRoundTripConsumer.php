<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Outbox;

use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Contracts\Clock;

/**
 * Consumes platform.diagnostics_round_trip_recorded.
 *
 * The Phase 00 reference consumer, and the subject of the exactly-once test.
 *
 * Idempotency here is structural rather than a flag check: the UPDATE is
 * conditional on `consumed_count = 0`, so a second delivery of the same event
 * matches no rows and changes nothing. That is the pattern real consumers
 * should copy — a read-then-write guard has a race between the read and the
 * write, whereas a conditional UPDATE is resolved by the database.
 *
 * `consumed_count` is nonetheless incremented on the first delivery only, so a
 * test can distinguish "delivered once" from "delivered twice, applied once".
 */
final class DiagnosticsRoundTripConsumer implements OutboxConsumer
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
    ) {}

    public function handles(): string
    {
        return 'platform.diagnostics_round_trip_recorded';
    }

    public function supportedVersions(): array
    {
        // Dual-read: v1 is the current producer; v2 is additive-optional and
        // ignored until a later phase switches the producer (events/README.md).
        return [1, 2];
    }

    public function consume(string $eventId, array $payload): void
    {
        $diagnosticsId = $payload['diagnostics_id'] ?? null;

        if (! is_string($diagnosticsId)) {
            // Malformed against the schema. Throwing routes it to retry and
            // eventually dead-letter rather than silently acknowledging.
            throw new \RuntimeException('diagnostics_id missing from payload');
        }

        $delayMs = (int) ($payload['echo_delay_ms'] ?? 0);

        if ($delayMs > 0) {
            // Simulated downstream latency for timing and lease-expiry tests.
            usleep(min($delayMs, 1000) * 1000);
        }

        // Conditional on consumed_count = 0: the second delivery of the same
        // event matches nothing and applies nothing. Exactly once in effect.
        $this->connection->table('platform_diagnostics')
            ->where('id', $diagnosticsId)
            ->where('consumed_count', 0)
            ->update([
                'consumed_at' => $this->clock->now()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.uP'),
                'consumed_count' => 1,
            ]);
    }
}
