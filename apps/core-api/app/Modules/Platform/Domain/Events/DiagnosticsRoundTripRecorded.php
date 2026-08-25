<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Events;

use App\Modules\Platform\Domain\ValueObjects\Classification;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

/**
 * platform.diagnostics_round_trip_recorded v1
 *
 * Payload contract:
 * packages/contracts/events/platform/diagnostics_round_trip_recorded.v1.schema.json
 *
 * The Phase 00 proof event. Recorded inside the transaction that writes the
 * diagnostics row, so the two commit together or not at all. It carries no
 * personal or clinical data and has no production consumer.
 */
final readonly class DiagnosticsRoundTripRecorded implements DomainEvent
{
    public function __construct(
        private Identifier $diagnosticsId,
        private string $label,
        private int $echoDelayMs,
        private DateTimeImmutable $recordedAt,
    ) {}

    public function eventType(): string
    {
        return 'platform.diagnostics_round_trip_recorded';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'Diagnostics';
    }

    public function aggregateId(): Identifier
    {
        return $this->diagnosticsId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function payload(): array
    {
        return [
            'diagnostics_id' => $this->diagnosticsId->value,
            'label' => $this->label,
            'echo_delay_ms' => $this->echoDelayMs,
            'recorded_at' => $this->recordedAt->format(DATE_RFC3339),
        ];
    }
}
