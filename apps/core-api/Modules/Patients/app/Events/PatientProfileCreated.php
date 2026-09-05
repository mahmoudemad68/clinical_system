<?php

declare(strict_types=1);

namespace Modules\Patients\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PatientProfileCreated implements DomainEvent
{
    public function __construct(
        private Identifier $patientId,
        private ?Identifier $linkedUserId,
        private string $sourceType,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'patient.profile_created';
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
     * @return array{patient_id: string, linked_user_id: string|null, source_type: string}
     */
    public function payload(): array
    {
        return [
            'patient_id' => $this->patientId->value,
            'linked_user_id' => $this->linkedUserId?->value,
            'source_type' => $this->sourceType,
        ];
    }
}
