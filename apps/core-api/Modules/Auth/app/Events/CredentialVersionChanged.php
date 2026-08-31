<?php

declare(strict_types=1);

namespace Modules\Auth\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class CredentialVersionChanged implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private int $credentialVersion,
        private string $reasonCode,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'auth.credential_version_changed';
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

    public function payload(): array
    {
        return [
            'user_id' => $this->userId->value,
            'credential_version' => $this->credentialVersion,
            'reason_code' => $this->reasonCode,
        ];
    }
}
