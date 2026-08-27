<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Enums\Currency;
use Modules\Platform\Exceptions\InvalidValueObject;
use Stringable;

/**
 * Exact money: integer minor units plus a currency code.
 *
 * Invariant 16 (docs/phases/README.md) forbids floating-point money. This class
 * is the only money representation in the system; a float or a string amount
 * anywhere else is a defect.
 *
 * Arithmetic is deliberately narrow. There is no divide() and no percentage
 * helper, because rounding policy for a discount, a tax, or a refund split is a
 * business decision owned by the module that needs it. A generic rounding rule
 * here would quietly pick a policy for POS and refunds that nobody approved.
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $amountMinor,
        public Currency $currency,
    ) {}

    public static function of(int $amountMinor, Currency $currency): self
    {
        return new self($amountMinor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            self::exactInt($this->amountMinor + $other->amountMinor, 'addition'),
            $this->currency,
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            self::exactInt($this->amountMinor - $other->amountMinor, 'subtraction'),
            $this->currency,
        );
    }

    /**
     * Multiply by a whole number, for example a line quantity.
     *
     * Integer factors only. A fractional factor would force a rounding policy,
     * and rounding policy for a discount, tax, or refund split belongs to the
     * module that owns the rule.
     */
    public function multiplyBy(int $factor): self
    {
        return new self(
            self::exactInt($this->amountMinor * $factor, 'multiplication'),
            $this->currency,
        );
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor
            && $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor > $other->amountMinor;
    }

    /**
     * The API contract shape: {"amount_minor": int, "currency": "EGP"}.
     *
     * @return array{amount_minor: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency->value,
        ];
    }

    /**
     * Human display only. Never persist or transmit this string.
     */
    public function __toString(): string
    {
        $scale = $this->currency->minorUnitScale();

        if ($scale === 0) {
            return sprintf('%d %s', $this->amountMinor, $this->currency->value);
        }

        $divisor = 10 ** $scale;
        $sign = $this->amountMinor < 0 ? '-' : '';
        $absolute = abs($this->amountMinor);

        return sprintf(
            '%s%d.%s %s',
            $sign,
            intdiv($absolute, $divisor),
            str_pad((string) ($absolute % $divisor), $scale, '0', STR_PAD_LEFT),
            $this->currency->value,
        );
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidValueObject(
                sprintf('Cannot combine %s and %s.', $this->currency->value, $other->currency->value),
            );
        }
    }

    /**
     * Assert an arithmetic result is still an exact integer.
     *
     * PHP does not raise on integer overflow: it silently promotes the result
     * to float, at which point the value is approximate and money arithmetic
     * has stopped being exact. Because the promotion happens *during* the
     * expression, the check must inspect the result's type — an earlier version
     * of this class compared magnitudes and called intdiv() on the result,
     * which threw TypeError on the very input it was meant to catch.
     *
     * A float here always means overflow, because both operands were integers.
     */
    private static function exactInt(int|float $result, string $operation): int
    {
        if (is_float($result)) {
            throw new InvalidValueObject(sprintf(
                'Money %s overflowed the exact integer range.',
                $operation,
            ));
        }

        return $result;
    }
}
