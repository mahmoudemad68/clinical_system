<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Exceptions\InvalidValueObject;
use Stringable;

/**
 * An exact quantity in an explicitly named smallest tracked unit.
 *
 * Invariant 16 forbids floating-point stock arithmetic. Beyond precision, the
 * unit matters for correctness: "3" of a medication is meaningless until you
 * know whether it counts tablets, strips, or boxes. Carrying the unit in the
 * type stops a box count from being subtracted from a tablet count, which is
 * the sort of error a stock ledger cannot detect after the fact.
 */
final readonly class Quantity implements Stringable
{
    private function __construct(
        public int $value,
        public string $unit,
    ) {}

    public static function of(int $value, string $unit): self
    {
        $normalizedUnit = trim($unit);

        if ($normalizedUnit === '') {
            throw new InvalidValueObject('Quantity requires an explicit unit.');
        }

        if (mb_strlen($normalizedUnit) > 32) {
            throw new InvalidValueObject('Quantity unit must be at most 32 characters.');
        }

        // Units are opaque identifiers, so they are compared byte for byte and
        // never locale-lowercased (phase file, database conventions).
        return new self($value, $normalizedUnit);
    }

    public static function zero(string $unit): self
    {
        return self::of(0, $unit);
    }

    public function add(self $other): self
    {
        $this->assertSameUnit($other);

        return new self($this->value + $other->value, $this->unit);
    }

    /**
     * Subtract, allowing a negative result.
     *
     * The stock ledger is append-only and records movements in both directions,
     * so negative intermediate values are legitimate here. Whether a resulting
     * balance may go negative is an Inventory rule, not a Quantity rule.
     */
    public function subtract(self $other): self
    {
        $this->assertSameUnit($other);

        return new self($this->value - $other->value, $this->unit);
    }

    public function isNegative(): bool
    {
        return $this->value < 0;
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value && $this->unit === $other->unit;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameUnit($other);

        return $this->value > $other->value;
    }

    /**
     * @return array{value: int, unit: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'unit' => $this->unit];
    }

    public function __toString(): string
    {
        return sprintf('%d %s', $this->value, $this->unit);
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->unit !== $other->unit) {
            throw new InvalidValueObject(
                sprintf('Cannot combine quantities in "%s" and "%s".', $this->unit, $other->unit),
            );
        }
    }
}
