<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Telemetry;

use App\Modules\Platform\Domain\Contracts\Redactor;
use App\Modules\Platform\Domain\Exceptions\RedactionFailure;

/**
 * Process-local telemetry. Request inspection is Telescope (local only).
 * This class redacts payloads and records bounded HTTP attributes for metrics.
 *
 * HTTP attributes: method, route, status class, request_id. Never patient,
 * doctor, appointment, file, prescription, or free-text values.
 */
final class TelemetryGateway
{
    /** @var list<array<string, mixed>> */
    private array $exportSnapshots = [];

    /** @var list<array<string, scalar>> */
    private array $httpSpans = [];

    public int $canaryDetections = 0;

    public function __construct(
        private readonly Redactor $redactor,
        private readonly bool $strict,
        private readonly string $serviceName = 'core-api',
        private readonly string $serviceVersion = '0.0.0-dev',
    ) {}

    /**
     * Record a deny-listed HTTP attribute set for metrics/tests.
     *
     * @param  array<string, scalar>  $attributes
     */
    public function startHttpSpan(string $name, array $attributes): void
    {
        $this->httpSpans[] = $this->bounded($attributes);
    }

    /**
     * @return list<array<string, scalar>>
     */
    public function httpSpans(): array
    {
        return $this->httpSpans;
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
        return true;
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
