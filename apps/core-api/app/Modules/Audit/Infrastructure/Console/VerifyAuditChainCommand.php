<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Console;

use App\Modules\Audit\Domain\Contracts\VerifyAuditChain;
use Illuminate\Console\Command;

final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify-chain';

    protected $description = 'Recompute the audit hash chain. Prints counts only; never row payloads.';

    public function handle(VerifyAuditChain $verifier): int
    {
        $result = $verifier->verify();
        $this->info(sprintf(
            'audit chain checked=%d ok=%s',
            $result['checked'],
            $result['ok'] ? 'yes' : 'no',
        ));

        if (! $result['ok']) {
            $this->error('audit chain verification failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
