<?php

declare(strict_types=1);

namespace Modules\Patients\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PatientAccountLinked implements DomainEvent
{
    public function __construct(
        private Identifier $patientId,
        private Identifier $userId,
        private string $assuranceLevel,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'patient.account_linked';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'PatientProfile';
    }

    public function aggregateId(): Identifier
    {
        return $this->patientId;
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
     * @return array{patient_id: string, user_id: string, assurance_level: string}
     */
    public function payload(): array
    {
        return [
            'patient_id' => $this->patientId->value,
            'user_id' => $this->userId->value,
            'assurance_level' => $this->assuranceLevel,
        ];
    }
}
