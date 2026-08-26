<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Classification;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final readonly class ProfileLinked implements DomainEvent
{
    public function __construct(
        private Identifier $userId,
        private string $profileType,
        private Identifier $profileId,
        private string $assuranceLevel,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'identity.profile_linked';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'IdentityProfileLink';
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
            'profile_type' => $this->profileType,
            'profile_id' => $this->profileId->value,
            'assurance_level' => $this->assuranceLevel,
        ];
    }
}
