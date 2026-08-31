<?php

declare(strict_types=1);

namespace Modules\Identity\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class AccountRegistered implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private string $status,
        private string $locale,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'identity.account_registered';
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
            'status' => $this->status,
            'locale' => $this->locale,
        ];
    }
}
