<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Console;

use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdempotencyStore;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Retention sweep for Platform tables (Phase 00 §4.4).
 *
 * Deletes only what is safe to delete:
 *
 *   - PROCESSED outbox rows past their retention window. DEAD_LETTER rows are
 *     never pruned: they are the operator's record of what failed, and silently
 *     removing them would defeat the point of having the state.
 *
 *   - Expired idempotency records. Retention must exceed the longest client
 *     retry and offline window, so this only removes keys no legitimate retry
 *     could still present.
 *
 *   - Synthetic diagnostics rows, which have no reason to accumulate.
 *
 * Deletion is chunked. An unbounded DELETE on a high-volume table takes a long
 * lock, which the phase file forbids.
 */
final class PlatformPruneCommand extends Command
{
    protected $signature = 'platform:prune
        {--chunk=1000 : Rows to delete per statement}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune processed outbox rows, expired idempotency keys, and diagnostics records';

    public function handle(
        ConnectionInterface $connection,
        IdempotencyStore $idempotency,
        Clock $clock,
    ): int {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $outboxRetentionDays = (int) config('platform.outbox.retention_days', 7);
        $cutoff = $clock->now()
            ->modify(sprintf('-%d days', $outboxRetentionDays))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.uP');

        // PROCESSED only. DEAD_LETTER rows survive until an operator resolves
        // them; pruning them would erase the evidence of a delivery failure.
        $outboxQuery = $connection->table('outbox_events')
            ->where('status', 'PROCESSED')
            ->where('processed_at', '<', $cutoff);

        if ($dryRun) {
            $this->table(
                ['target', 'rows'],
                [
                    ['outbox_events (PROCESSED)', (string) $outboxQuery->count()],
                    ['idempotency_keys (expired)', (string) $connection->table('idempotency_keys')
                        ->where('expires_at', '<', $clock->now()->format('Y-m-d H:i:s.uP'))->count()],
                    ['platform_diagnostics', (string) $connection->table('platform_diagnostics')
                        ->where('recorded_at', '<', $cutoff)->count()],
                ],
            );

            return self::SUCCESS;
        }

        $outboxDeleted = $this->deleteInChunks(
            fn (): int => $connection->table('outbox_events')
                ->where('status', 'PROCESSED')
                ->where('processed_at', '<', $cutoff)
                ->limit($chunk)
                ->delete(),
        );

        $idempotencyDeleted = $idempotency->purgeExpired();

        $diagnosticsDeleted = $this->deleteInChunks(
            fn (): int => $connection->table('platform_diagnostics')
                ->where('recorded_at', '<', $cutoff)
                ->limit($chunk)
                ->delete(),
        );

        $this->info(sprintf(
            'Pruned: outbox=%d idempotency=%d diagnostics=%d',
            $outboxDeleted,
            $idempotencyDeleted,
            $diagnosticsDeleted,
        ));

        return self::SUCCESS;
    }

    /**
     * Delete repeatedly until a pass removes nothing.
     *
     * Chunking keeps each statement's lock short. The safety bound stops a
     * pathological loop from running forever if rows are being inserted as
     * fast as they are removed.
     *
     * @param callable(): int $deleteOnce
     */
    private function deleteInChunks(callable $deleteOnce): int
    {
        $total = 0;
        $passes = 0;

        do {
            $deleted = $deleteOnce();
            $total += $deleted;
            $passes++;
        } while ($deleted > 0 && $passes < 1000);

        return $total;
    }
}
