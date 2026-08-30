<?php

declare(strict_types=1);

namespace Modules\Audit\Console;

use Illuminate\Console\Command;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;
use Modules\Audit\Services\Checkpoint\CreateAuditChainCheckpoint;
use Throwable;

final class CheckpointAuditChainCommand extends Command
{
    protected $signature = 'audit:checkpoint-chain';

    protected $description = 'Verify the audit hash chain, then sign and store an external checkpoint of the current tip.';

    public function handle(CreateAuditChainCheckpoint $checkpoints): int
    {
        try {
            $result = $checkpoints->create();
        } catch (AuditChainCheckpointFailed $e) {
            $this->error(sprintf('audit checkpoint failed reason=%s', $e->reason));

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('audit checkpoint failed reason=checkpoint_failed');

            return self::FAILURE;
        }

        if ($result['skipped'] !== null) {
            $this->info(sprintf('audit checkpoint skipped reason=%s', $result['skipped']));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'audit checkpoint created sequence=%d key_id=%s',
            (int) $result['sequence'],
            (string) config('audit.checkpoint.key_id', 'v1'),
        ));

        return self::SUCCESS;
    }
}
