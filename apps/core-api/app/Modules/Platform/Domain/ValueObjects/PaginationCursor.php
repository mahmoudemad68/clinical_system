<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;

/**
 * An opaque, signed, scope-bound pagination cursor.
 *
 * The phase file requires cursors to be opaque, signed when they carry state,
 * size-bounded, and scoped to filter, order, and actor. The scope binding is
 * the security-relevant part: without it a cursor is a client-supplied
 * position into a server-side result set, and a cursor minted for one actor's
 * filtered query could be replayed against another's.
 *
 * This class owns the shape and the scope check. It does not own the signing
 * key: signing happens in Infrastructure through CursorSigner, so the domain
 * never touches application secrets.
 *
 * @phpstan-type CursorPayload array{s: string, k: array<string, scalar>, v: int}
 */
final readonly class PaginationCursor
{
    public const MAX_ENCODED_LENGTH = 512;

    public const CURRENT_VERSION = 1;

    /**
     * @param  array<string, scalar>  $position  Keyset position, for example the last id and sort value.
     */
    private function __construct(
        public string $scopeHash,
        public array $position,
        public int $version,
    ) {}

    /**
     * Mint a cursor bound to the scope that produced it.
     *
     * @param  array<string, scalar>  $position
     */
    public static function forScope(CursorScope $scope, array $position): self
    {
        if ($position === []) {
            throw new InvalidValueObject('A pagination cursor requires a keyset position.');
        }

        return new self($scope->hash(), $position, self::CURRENT_VERSION);
    }

    /**
     * Rebuild from a decoded, signature-verified payload.
     *
     * The caller must have verified the signature already. This method assumes
     * integrity and checks only structure and scope.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromVerifiedPayload(array $payload, CursorScope $expectedScope): self
    {
        $version = $payload['v'] ?? null;
        $scopeHash = $payload['s'] ?? null;
        $position = $payload['k'] ?? null;

        if (! is_int($version) || ! is_string($scopeHash) || ! is_array($position) || $position === []) {
            throw new InvalidValueObject('Cursor payload is malformed.');
        }

        if ($version !== self::CURRENT_VERSION) {
            // A cursor from an older deployment is refused rather than guessed
            // at. The client restarts pagination, which is correct and cheap.
            throw new InvalidValueObject('Cursor version is no longer supported.');
        }

        // hash_equals, not ===, so a mismatch cannot be probed by timing.
        if (! hash_equals($expectedScope->hash(), $scopeHash)) {
            throw new InvalidValueObject('Cursor does not belong to this query scope.');
        }

        /** @var array<string, scalar> $position */
        return new self($scopeHash, $position, $version);
    }

    /**
     * @return CursorPayload
     */
    public function toPayload(): array
    {
        return ['s' => $this->scopeHash, 'k' => $this->position, 'v' => $this->version];
    }
}
