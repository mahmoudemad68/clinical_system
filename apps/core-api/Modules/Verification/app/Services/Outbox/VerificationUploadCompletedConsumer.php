<?php

declare(strict_types=1);

namespace Modules\Verification\Services\Outbox;

use Modules\Platform\Services\Outbox\OutboxConsumer;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationUploadProcessor;

final class VerificationUploadCompletedConsumer implements OutboxConsumer
{
    public function __construct(
        private readonly VerificationUploadProcessor $processor,
    ) {}

    public function handles(): string
    {
        return 'verification.upload_completed';
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function consume(string $eventId, array $payload): void
    {
        unset($eventId);
        $uploadId = Identifier::fromString((string) ($payload['upload_id'] ?? ''));
        $this->processor->process($uploadId);
    }
}
