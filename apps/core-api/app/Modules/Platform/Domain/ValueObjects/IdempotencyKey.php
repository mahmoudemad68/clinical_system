<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;

/**
 * A server-scoped idempotency key.
 *
 * The client supplies a random key per user intent. The server scopes it to the
 * authenticated actor/device, the operation, and the tenant where applicable
 * (phase file, "Idempotency contract" step 2).
 *
 * Scoping is the security property. An unscoped key is a global namespace that
 * any authenticated caller can write into: presenting another user's key would
 * either replay their result back to you or block their operation. Neither is
 * acceptable, so the stored key is always the composite below and never the raw
 * client string.
 */
final readonly class IdempotencyKey
{
    private const MIN_CLIENT_KEY_LENGTH = 16;
    private const MAX_CLIENT_KEY_LENGTH = 255;
    private const CLIENT_KEY_PATTERN = '/^[A-Za-z0-9._~-]+$/';

    private function __construct(
        public string $storageKey,
        public string $operationId,
    ) {
    }

    /**
     * Build the scoped key from the client-supplied header and server-owned context.
     *
     * Every scoping component comes from the server's own view of the request.
     * A client-supplied actor or tenant here would defeat the whole mechanism.
     */
    public static function scope(
        string $clientKey,
        string $operationId,
        string $actorKey,
        ?string $tenantKey = null,
    ): self {
        $trimmed = trim($clientKey);

        if (mb_strlen($trimmed) < self::MIN_CLIENT_KEY_LENGTH) {
            throw new InvalidValueObject('Idempotency-Key must be at least 16 characters.');
        }

        if (mb_strlen($trimmed) > self::MAX_CLIENT_KEY_LENGTH) {
            throw new InvalidValueObject('Idempotency-Key must be at most 255 characters.');
        }

        if (preg_match(self::CLIENT_KEY_PATTERN, $trimmed) !== 1) {
            throw new InvalidValueObject('Idempotency-Key may contain only unreserved URL characters.');
        }

        // Hashed so the stored key reveals neither the client's key nor the
        // actor and tenant identifiers to anyone reading the table.
        $composite = implode("\0", [$operationId, $actorKey, $tenantKey ?? '', $trimmed]);

        return new self(hash('sha256', $composite), $operationId);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->storageKey, $other->storageKey);
    }
}
