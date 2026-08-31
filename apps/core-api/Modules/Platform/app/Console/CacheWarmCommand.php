<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Services\Cache\CacheWarmer;

/**
 * Restores the public/internal cache entries that a Redis flush is allowed to
 * drop (ADR 0007). Never writes PHI.
 */
final class CacheWarmCommand extends Command
{
    protected $signature = 'platform:cache-warm';

    protected $description = 'Warm public platform cache keys after a Redis flush';

    public function handle(CacheWarmer $warmer): int
    {
        $warmer->warm();
        $this->info('Warmed '.CacheWarmer::VERSION_KEY.' and '.CacheWarmer::READINESS_KEY);

        return self::SUCCESS;
    }
}
