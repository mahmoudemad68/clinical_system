<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Short-lived private upload grant. The storage locator and signed URL are
 * infrastructure details. They must not be logged, metered, or evented.
 *
 * @phpstan-type HeaderMap array<string, string>
 */
final readonly class ObjectUploadGrant
{
    /**
     * @param  HeaderMap  $headers
     */
    public function __construct(
        public string $objectId,
        public string $storageLocator,
        public string $method,
        public string $url,
        public array $headers,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * @return array{method: string, expires_at: string}
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'expires_at' => $this->expiresAt->format(DateTimeInterface::ATOM),
        ];
    }
}
