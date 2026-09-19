<?php

declare(strict_types=1);

namespace Modules\Doctors\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class DoctorProfileCreated implements DomainEvent
{
    public function __construct(
        private Identifier $doctorId,
        private Identifier $linkedUserId,
        private string $sourceType,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'doctor.profile_created';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'DoctorProfile';
    }

    public function aggregateId(): Identifier
    {
        return $this->doctorId;
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
     * @return array{doctor_id: string, linked_user_id: string, source_type: string}
     */
    public function payload(): array
    {
        return [
            'doctor_id' => $this->doctorId->value,
            'linked_user_id' => $this->linkedUserId->value,
            'source_type' => $this->sourceType,
        ];
    }
}
