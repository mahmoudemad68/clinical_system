<?php

declare(strict_types=1);

namespace Modules\Auth\Events;

use DateTimeImmutable;
use Modules\Auth\Enums\RecoveryNoticeKind;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class RecoveryOldChannelNoticeRequested implements DomainEvent
{
    public function __construct(
        private Identifier $recoveryRequestId,
        private RecoveryNoticeKind $noticeKind,
        private string $locale,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'auth.recovery_old_channel_notice_requested';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'RecoveryRequest';
    }

    public function aggregateId(): Identifier
    {
        return $this->recoveryRequestId;
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
            'recovery_request_id' => $this->recoveryRequestId->value,
            'notice_kind' => $this->noticeKind->value,
            'locale' => $this->locale,
        ];
    }
}
