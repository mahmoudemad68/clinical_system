<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use DateTimeImmutable;
use Modules\Platform\Enums\IdempotencyState;

/**
 * A stored idempotency outcome.
 *
 * Holds a canonical request hash, a state, a status code, a safe response
 * reference, and an expiry. Deliberately not the response body: storing a
 * booking confirmation or a prescription payload here would copy clinical and
 * financial content into a table with different retention and access rules.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $requestHash,
        public IdempotencyState $state,
        public ?int $statusCode,
        public ?string $responseReference,
        public ?string $safeErrorClass,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Does an incoming request match the one this record was created for?
     *
     * hash_equals rather than ===, so a mismatch cannot be probed by timing.
     */
    public function matchesRequest(string $incomingHash): bool
    {
        return hash_equals($this->requestHash, $incomingHash);
    }

    /**
     * Can the original outcome be replayed for this request?
     *
     * Only when the operation succeeded and the request is byte-for-byte the
     * same intent. A different payload under the same key is a client bug or an
     * attack, and it answers 409 IDEMPOTENCY_KEY_REUSED instead.
     */
    public function canReplayFor(string $incomingHash): bool
    {
        return $this->state === IdempotencyState::Succeeded && $this->matchesRequest($incomingHash);
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
