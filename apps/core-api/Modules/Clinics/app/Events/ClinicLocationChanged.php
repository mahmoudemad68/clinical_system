<?php

declare(strict_types=1);

namespace Modules\Clinics\Events;

use DateTimeImmutable;
use Modules\Clinics\Enums\ClinicLocationChangeType;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class ClinicLocationChanged implements DomainEvent
{
    public function __construct(
        private Identifier $locationId,
        private Identifier $doctorId,
        private int $version,
        private ClinicLocationChangeType $changeType,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'clinic.location_changed';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'ClinicLocation';
    }

    public function aggregateId(): Identifier
    {
        return $this->locationId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function classification(): Classification
    {
        return Classification::Personal;
    }

    /**
     * @return array{location_id: string, doctor_id: string, version: int, change_type: string}
     */
    public function payload(): array
    {
        return [
            'location_id' => $this->locationId->value,
            'doctor_id' => $this->doctorId->value,
            'version' => $this->version,
            'change_type' => $this->changeType->value,
        ];
    }
}
