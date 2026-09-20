<?php

declare(strict_types=1);

namespace Modules\Verification\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

/**
 * Client upload completion was accepted. The server may now observe the object.
 * Payload is upload_id only.
 */
final readonly class VerificationUploadCompleted implements DomainEvent
{
    public function __construct(
        private Identifier $uploadId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'verification.upload_completed';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'VerificationUploadIntent';
    }

    public function aggregateId(): Identifier
    {
        return $this->uploadId;
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
     * @return array{upload_id: string}
     */
    public function payload(): array
    {
        return [
            'upload_id' => $this->uploadId->value,
        ];
    }
}
