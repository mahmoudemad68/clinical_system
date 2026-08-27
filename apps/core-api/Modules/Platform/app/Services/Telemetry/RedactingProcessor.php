<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Telemetry;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that redacts every record before it is written.
 *
 * Wired through the channel `tap` so a developer cannot "just log" around it
 * by picking a different helper. The only channel without a tap is `null`.
 */
final class RedactingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TelemetryGateway $telemetry,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $payload = [
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ];

        $redacted = $this->telemetry->redactForExport($payload);

        $message = is_string($redacted['message'] ?? null) ? $redacted['message'] : $record->message;
        $context = is_array($redacted['context'] ?? null) ? $redacted['context'] : [];
        $extra = is_array($redacted['extra'] ?? null) ? $redacted['extra'] : [];

        foreach (array_keys($context) as $key) {
            if (is_string($key) && $this->forbiddenMetricLabel($key)) {
                unset($context[$key]);
            }
        }

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    private function forbiddenMetricLabel(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-'], '', $key));

        return in_array($normalized, ['patientid', 'doctorid', 'appointmentid', 'prescriptionid', 'fileid', 'userid'], true);
    }
}
