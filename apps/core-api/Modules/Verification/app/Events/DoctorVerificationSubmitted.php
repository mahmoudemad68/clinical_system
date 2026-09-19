<?php

declare(strict_types=1);

namespace Modules\Verification\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class DoctorVerificationSubmitted implements DomainEvent
{
    public function __construct(
        private Identifier $doctorId,
        private Identifier $caseId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'doctor.verification_submitted';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'VerificationCase';
    }

    public function aggregateId(): Identifier
    {
        return $this->caseId;
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
     * @return array{doctor_id: string, case_id: string}
     */
    public function payload(): array
    {
        return [
            'doctor_id' => $this->doctorId->value,
            'case_id' => $this->caseId->value,
        ];
    }
}
