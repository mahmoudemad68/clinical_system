<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Classification;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final readonly class StatusChanged implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private string $oldStatus,
        private string $newStatus,
        private string $reasonCode,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'identity.status_changed';
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
        return Classification::Personal;
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId->value,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'reason_code' => $this->reasonCode,
        ];
    }
}
