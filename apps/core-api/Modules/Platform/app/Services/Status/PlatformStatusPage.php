<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Status;

/**
 * Safe Inertia projection for a Phase 00 persona status page.
 *
 * No dependency names, hostnames, checks, Telescope, or infrastructure.
 */
final readonly class PlatformStatusPage
{
    public function __construct(
        public string $service,
        public string $version,
        public string $status,
    ) {}
}
