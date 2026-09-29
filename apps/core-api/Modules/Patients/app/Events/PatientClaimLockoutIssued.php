<?php

declare(strict_types=1);

namespace Modules\Patients\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PatientClaimLockoutIssued implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'patient.claim_lockout_issued';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'User';
    }

    public function aggregateId(): Identifier
    {
        return $this->userId;
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
        return ['reason_code' => 'failed_proof_lockout'];
    }
}
