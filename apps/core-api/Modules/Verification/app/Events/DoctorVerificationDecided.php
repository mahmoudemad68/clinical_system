<?php

declare(strict_types=1);

namespace Modules\Verification\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class DoctorVerificationDecided implements DomainEvent
{
    public function __construct(
        private Identifier $doctorId,
        private Identifier $caseId,
        private string $decision,
        private string $reasonCode,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'doctor.verification_decided';
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
     * @return array{doctor_id: string, case_id: string, decision: string, reason_code: string}
     */
    public function payload(): array
    {
        return [
            'doctor_id' => $this->doctorId->value,
            'case_id' => $this->caseId->value,
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
        ];
    }
}
