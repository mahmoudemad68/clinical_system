<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Telemetry;

/**
 * Prometheus text exposition for operational scraping.
 *
 * Not part of the public API envelope. Must not be routed through the gateway.
 */
interface MetricsExposition
{
    public function render(): string;
}
