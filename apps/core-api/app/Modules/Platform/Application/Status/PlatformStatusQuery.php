<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Status;

/**
 * Process liveness for first-party Inertia surfaces — not readiness reconnaissance.
 */
final class PlatformStatusQuery
{
    public function __construct(
        private readonly string $version,
    ) {}

    public function handle(): PlatformStatusPage
    {
        return new PlatformStatusPage(
            service: 'core-api',
            version: $this->version,
            status: 'up',
        );
    }
}
