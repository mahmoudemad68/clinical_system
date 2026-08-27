<?php

declare(strict_types=1);

namespace Modules\Auth\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class OtpDeliveryRequested implements DomainEvent
{
    public function __construct(
        private Identifier $otpRequestId,
        private string $destinationHandle,
        private string $locale,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'auth.otp_delivery_requested';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'OtpRequest';
    }

    public function aggregateId(): Identifier
    {
        return $this->otpRequestId;
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
            'otp_request_id' => $this->otpRequestId->value,
            'destination_handle' => $this->destinationHandle,
            'locale' => $this->locale,
        ];
    }
}
