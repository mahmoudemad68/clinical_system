<?php

declare(strict_types=1);

namespace Modules\Patients\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PatientProfileDisputed implements DomainEvent
{
    public function __construct(
        private Identifier $patientId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'patient.profile_disputed';
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
        return Classification::Internal;
    }

    /**
     * @return array{reason_code: string}
     */
    public function payload(): array
    {
        return ['reason_code' => 'dispute_freeze'];
    }
}
