<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Telemetry;

use App\Modules\Platform\Domain\Contracts\Redactor;
use App\Modules\Platform\Domain\Exceptions\RedactionFailure;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * Process-local telemetry. Spans and export snapshots are redacted before they
 * leave this class. That is the G-07-05 boundary: a synthetic clinical-looking
 * payload is scrubbed here, not at the collector.
 *
 * HTTP spans carry only bounded attributes: method, route, status class,
 * request_id. Never patient, doctor, appointment, file, prescription, or
 * free-text values.
 */
final class TelemetryGateway
{
    private readonly InMemoryExporter $memoryExporter;

    private readonly TracerProvider $tracerProvider;

    private readonly TracerInterface $tracer;

    /** @var list<array<string, mixed>> */
    private array $exportSnapshots = [];

    public int $canaryDetections = 0;

    public function __construct(
        private readonly Redactor $redactor,
        private readonly bool $strict,
        private readonly string $serviceName = 'core-api',
        private readonly string $serviceVersion = '0.0.0-dev',
        ?SpanExporterInterface $otlpExporter = null,
    ) {
        $this->memoryExporter = new InMemoryExporter;
        $processors = [new SimpleSpanProcessor($this->memoryExporter)];

        if ($otlpExporter !== null) {
            $processors[] = new SimpleSpanProcessor($otlpExporter);
        }

        $this->tracerProvider = new TracerProvider($processors);
        $this->tracer = $this->tracerProvider->getTracer($this->serviceName, $this->serviceVersion);
    }

    public function tracer(): TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Start an HTTP server span with a deny-listed attribute set.
     *
     * @param  array<string, scalar>  $attributes
     */
    public function startHttpSpan(string $name, array $attributes): void
    {
        $safe = $this->bounded($attributes);

        $this->tracer->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($safe)
            ->startSpan()
            ->end();
    }

    /**
     * Redact a payload as if it were leaving the process.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redactForExport(array $payload): array
    {
        $redacted = $this->redactor->redactArray($payload);
        $serialized = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (is_string($serialized) && $this->redactor->containsSensitiveValue($serialized)) {
            $this->canaryDetections++;

            if ($this->strict) {
                throw new RedactionFailure('A sensitive value survived redaction on the export path.');
            }

            return ['dropped' => true, 'reason' => 'redaction_canary'];
        }

        return $redacted;
    }

    /**
     * Capture a redacted snapshot for the export-path canary suite (G-07-05).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function captureExport(array $payload): array
    {
        $redacted = $this->redactForExport($payload);
        $this->exportSnapshots[] = $redacted;

        return $redacted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshots(): array
    {
        return $this->exportSnapshots;
    }

    public function forceFlush(): bool
    {
        try {
            return $this->tracerProvider->forceFlush();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, scalar>  $attributes
     * @return array<string, scalar>
     */
    private function bounded(array $attributes): array
    {
        $allowed = ['service', 'version', 'method', 'route', 'status_class', 'status', 'request_id', 'http.scheme'];
        $out = [];

        foreach ($attributes as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            $out[$key] = $value;
        }

        $out['service'] = $this->serviceName;
        $out['version'] = $this->serviceVersion;

        return $out;
    }
}
