<?php

declare(strict_types=1);

namespace Modules\Audit\Console;

use Illuminate\Console\Command;
use Modules\Audit\Contracts\VerifyAuditChain;

final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify-chain';

    protected $description = 'Recompute the audit hash chain and verify external checkpoints. Prints counts only; never row payloads.';

    public function handle(VerifyAuditChain $verifier): int
    {
        $result = $verifier->verify();
        $checkpoint = $result['checkpoint_ok'] === null
            ? 'skipped'
            : ($result['checkpoint_ok'] ? 'yes' : 'no');

        $this->info(sprintf(
            'audit chain checked=%d ok=%s checkpoint=%s',
            $result['checked'],
            $result['ok'] ? 'yes' : 'no',
            $checkpoint,
        ));

        if ($result['checkpoint_reason'] !== null && $result['checkpoint_ok'] !== true) {
            $this->error(sprintf('audit checkpoint verification failed reason=%s', $result['checkpoint_reason']));
        }

        if (! $result['ok']) {
            $this->error('audit chain verification failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
