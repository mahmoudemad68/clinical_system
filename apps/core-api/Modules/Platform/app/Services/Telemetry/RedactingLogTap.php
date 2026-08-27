<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Telemetry;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Laravel logging tap. Attaches the redacting processor to every Monolog
 * handler on the channel. A channel that is not Monolog is left alone; the
 * `null` driver never emits.
 */
final class RedactingLogTap
{
    public function __construct(
        private readonly TelemetryGateway $telemetry,
    ) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $monolog->pushProcessor(new RedactingProcessor($this->telemetry));
    }
}
