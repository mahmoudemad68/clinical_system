<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Telemetry;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Throwable;

/**
 * Telemetry must not take the request path down. An unreachable collector is
 * a degraded signal, not a core outage (Phase 00 §7).
 */
final class FailSafeSpanExporter implements SpanExporterInterface
{
    public function __construct(private readonly SpanExporterInterface $inner) {}

    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        try {
            return $this->inner->export($batch, $cancellation)->catch(static fn (): mixed => false);
        } catch (Throwable) {
            return new CompletedFuture(false);
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->shutdown($cancellation);
        } catch (Throwable) {
            return false;
        }
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->forceFlush($cancellation);
        } catch (Throwable) {
            return false;
        }
    }
}
