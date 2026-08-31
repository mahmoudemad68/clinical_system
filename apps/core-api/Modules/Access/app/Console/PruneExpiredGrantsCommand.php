<?php

declare(strict_types=1);

namespace Modules\Access\Console;

use Illuminate\Console\Command;
use Modules\Access\Contracts\GrantStore;
use Modules\Platform\Contracts\Clock;

final class PruneExpiredGrantsCommand extends Command
{
    protected $signature = 'access:prune-expired';

    protected $description = 'Delete obsolete contextual grants. ENGINEERING_DEFAULT retention; not a statutory period.';

    public function handle(GrantStore $grants, Clock $clock): int
    {
        $deleted = $grants->pruneObsolete($clock->now());
        $this->info(sprintf('Obsolete contextual grants pruned (deleted=%d).', $deleted));

        return self::SUCCESS;
    }
}
