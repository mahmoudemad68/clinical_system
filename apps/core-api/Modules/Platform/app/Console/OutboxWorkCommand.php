<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Services\Outbox\OutboxDispatcher;

/**
 * Runs the outbox dispatcher.
 *
 * Two modes:
 *   --once   process one batch and exit (used by tests and by CI)
 *   default  loop until stopped, which is how it runs in a container
 *
 * Graceful shutdown matters here (Phase 00 §2.2). On SIGTERM the loop finishes
 * the batch in flight and then exits, rather than dying mid-batch and leaving
 * rows claimed until their lease expires. Rows would recover either way, but a
 * clean stop avoids a delivery delay on every deployment.
 */
final class OutboxWorkCommand extends Command
{
    protected $signature = 'outbox:work
        {--once : Process a single batch and exit}
        {--sleep=1 : Seconds to idle when no work is available}
        {--max-batches=0 : Stop after this many batches; 0 means unlimited}';

    protected $description = 'Claim and dispatch transactional outbox events';

    private bool $shouldStop = false;

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $this->registerSignalHandlers();

        $sleep = max(0, (int) $this->option('sleep'));
        $maxBatches = max(0, (int) $this->option('max-batches'));
        $batches = 0;

        do {
            // Reclaim anything a dead worker left behind before taking new work.
            $recovered = $dispatcher->recoverExpiredLeases();

            if ($recovered > 0) {
                $this->warn(sprintf('Recovered %d row(s) from expired leases.', $recovered));
            }

            $processed = $dispatcher->dispatchBatch();
            $batches++;

            if ($processed > 0) {
                $this->line(sprintf('Processed %d event(s).', $processed));
            }

            if ($this->option('once') || $this->shouldStop) {
                break;
            }

            if ($maxBatches > 0 && $batches >= $maxBatches) {
                break;
            }

            if ($processed === 0 && $sleep > 0) {
                sleep($sleep);
            }
        } while (! $this->shouldStop);

        return self::SUCCESS;
    }

    /**
     * Stop after the current batch rather than mid-flight.
     */
    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
                $this->info('Shutdown requested; finishing current batch.');
            });
        }
    }
}
