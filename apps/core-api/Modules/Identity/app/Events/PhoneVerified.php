<?php

declare(strict_types=1);

namespace Modules\Identity\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PhoneVerified implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private DateTimeImmutable $verifiedAt,
    ) {}

    public function eventType(): string
    {
        return 'identity.phone_verified';
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
        return $this->verifiedAt;
    }

    public function classification(): Classification
    {
        return Classification::Personal;
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId->value,
            'verified_at' => $this->verifiedAt->format(DATE_RFC3339),
        ];
    }
}
