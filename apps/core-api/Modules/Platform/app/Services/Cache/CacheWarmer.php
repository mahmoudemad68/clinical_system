<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Cache;

use Illuminate\Contracts\Cache\Repository;

/**
 * Warms the caches the request path is allowed to miss.
 *
 * An empty Redis after restart must degrade performance, not lose truth
 * (ADR 0007). This warmer puts back only public/internal entries: version
 * metadata and the readiness snapshot. It never writes PHI.
 */
final class CacheWarmer
{
    public const VERSION_KEY = 'platform:meta:version';

    public const READINESS_KEY = 'platform:ready:flag';

    public function __construct(
        private readonly Repository $cache,
        private readonly string $version,
    ) {}

    public function warm(): void
    {
        $this->cache->put(self::VERSION_KEY, $this->version, 60);
        $this->cache->put(self::READINESS_KEY, '1', 10);
    }

    public function version(): ?string
    {
        $value = $this->cache->get(self::VERSION_KEY);

        return is_string($value) ? $value : null;
    }
}
