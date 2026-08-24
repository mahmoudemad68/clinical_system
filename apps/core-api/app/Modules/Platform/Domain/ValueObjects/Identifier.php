<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use Stringable;

/**
 * A UUIDv7 identifier (ADR 0005).
 *
 * Validated on construction, so an identifier that exists is well-formed. This
 * is deliberately strict about the version nibble and the RFC 4122 variant
 * bits: accepting a v4 here would silently reintroduce the random-insert
 * behaviour ADR 0005 exists to avoid, and it would do so invisibly.
 */
final readonly class Identifier implements Stringable
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(public string $value)
    {
    }

    /**
     * Build from an untrusted string.
     *
     * @throws InvalidValueObject when the value is not a lowercase UUIDv7.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            // The message names the expectation but never echoes the input:
            // an identifier can appear in an error surfaced to a client.
            throw new InvalidValueObject('Identifier must be a UUID version 7.');
        }

        return new self($normalized);
    }

    /**
     * Build from a value the application itself produced.
     *
     * Still validated. "We generated it" is an assumption, and an assumption
     * that is cheap to check is worth checking.
     */
    public static function fromTrusted(string $value): self
    {
        return self::fromString($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The embedded creation timestamp in milliseconds since the Unix epoch.
     *
     * Useful for diagnostics and retention. Never use it as an authoritative
     * creation time: the authoritative time is a persisted column.
     */
    public function timestampMilliseconds(): int
    {
        return (int) hexdec(substr(str_replace('-', '', $this->value), 0, 12));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
