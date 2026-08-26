<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Classification;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final readonly class SessionRevoked implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private Identifier $sessionId,
        private string $reasonCode,
        private DateTimeImmutable $revokedAt,
    ) {}

    public function eventType(): string
    {
        return 'auth.session_revoked';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'AuthSession';
    }

    public function aggregateId(): Identifier
    {
        return $this->sessionId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId->value,
            'session_id' => $this->sessionId->value,
            'reason_code' => $this->reasonCode,
            'revoked_at' => $this->revokedAt->format(DATE_RFC3339),
        ];
    }
}
