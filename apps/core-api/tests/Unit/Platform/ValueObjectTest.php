<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Enums\CountryCode;
use Modules\Platform\Enums\Currency;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Services\Identity\UuidV7Generator;
use Modules\Platform\Services\Time\SystemClock;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\Money;
use Modules\Platform\Support\Quantity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Platform primitives (Phase 00 test plan, "Unit tests").
 *
 * Covers UUIDv7, money, quantity, Cairo time conversion including the DST
 * boundary, and the classification rules that govern telemetry and caching.
 */
final class ValueObjectTest extends TestCase
{
    // ------------------------------------------------------------ UUIDv7

    #[Test]
    public function it_generates_version_7_identifiers(): void
    {
        $generator = new UuidV7Generator;

        for ($i = 0; $i < 50; $i++) {
            $id = $generator->next();
            // The version nibble is what distinguishes v7 from v4. Accepting a
            // v4 here would silently reintroduce random index insertion, which
            // is the entire cost ADR 0005 exists to avoid.
            $this->assertSame('7', $id->value[14], "Identifier {$id->value} is not version 7.");
        }
    }

    #[Test]
    public function generated_identifiers_are_non_decreasing(): void
    {
        $generator = new UuidV7Generator;

        $previous = $generator->next()->value;
        for ($i = 0; $i < 200; $i++) {
            $current = $generator->next()->value;
            $this->assertGreaterThanOrEqual(
                0,
                strcmp($current, $previous),
                'UUIDv7 generation must be monotonic within a process for index locality.',
            );
            $previous = $current;
        }
    }

    #[Test]
    public function it_rejects_a_version_4_identifier(): void
    {
        $this->expectException(InvalidValueObject::class);

        Identifier::fromString('f47ac10b-58cc-4372-a567-0e02b2c3d479');
    }

    #[Test]
    public function it_rejects_a_malformed_identifier_without_echoing_it(): void
    {
        try {
            Identifier::fromString('<script>alert(1)</script>');
            $this->fail('Expected InvalidValueObject.');
        } catch (InvalidValueObject $e) {
            // The message must never quote the input: exception messages reach
            // logs, and quoting input is how sensitive values get there.
            $this->assertStringNotContainsString('script', $e->getMessage());
        }
    }

    // ------------------------------------------------------------- money

    #[Test]
    public function money_arithmetic_is_exact(): void
    {
        $a = Money::of(1050, Currency::EGP);   // 10.50 EGP
        $b = Money::of(2075, Currency::EGP);   // 20.75 EGP

        $this->assertSame(3125, $a->add($b)->amountMinor);
        $this->assertSame(-1025, $a->subtract($b)->amountMinor);
        $this->assertSame(3150, $a->multiplyBy(3)->amountMinor);
    }

    #[Test]
    public function money_carries_its_currency_so_mixing_is_detectable(): void
    {
        // V1 has exactly one currency, so two different currencies cannot be
        // constructed to test the guard directly. What *can* be asserted now is
        // the precondition that makes the guard possible: currency travels on
        // every value rather than being assumed globally.
        //
        // When a second currency is added, assertSameCurrency() starts doing
        // real work and this test should grow a mixing case. The failure it
        // prevents — summing two currencies as if they were one number — is
        // silent, which is exactly why the field exists before it is needed.
        $money = Money::of(1050, Currency::EGP);

        $this->assertSame(Currency::EGP, $money->currency);
        $this->assertSame('EGP', $money->toArray()['currency']);
        $this->assertSame(CountryCode::EG, Currency::EGP->country());

        // Same currency combines without complaint.
        $this->assertSame(2100, $money->add(Money::of(1050, Currency::EGP))->amountMinor);
    }

    #[Test]
    public function money_renders_with_the_currency_scale(): void
    {
        $this->assertSame('10.50 EGP', (string) Money::of(1050, Currency::EGP));
        $this->assertSame('-3.07 EGP', (string) Money::of(-307, Currency::EGP));
        $this->assertSame('0.00 EGP', (string) Money::zero(Currency::EGP));
    }

    #[Test]
    public function money_serialises_to_the_contract_shape(): void
    {
        $this->assertSame(
            ['amount_minor' => 1050, 'currency' => 'EGP'],
            Money::of(1050, Currency::EGP)->toArray(),
        );
    }

    #[Test]
    public function money_detects_multiplication_overflow(): void
    {
        $this->expectException(InvalidValueObject::class);

        Money::of(PHP_INT_MAX, Currency::EGP)->multiplyBy(2);
    }

    // ---------------------------------------------------------- quantity

    #[Test]
    public function quantity_requires_an_explicit_unit(): void
    {
        $this->expectException(InvalidValueObject::class);

        Quantity::of(5, '   ');
    }

    #[Test]
    public function quantity_refuses_to_mix_units(): void
    {
        // "3" is meaningless until you know whether it counts tablets or boxes.
        // A stock ledger cannot detect this error after the fact.
        $this->expectException(InvalidValueObject::class);

        Quantity::of(5, 'tablet')->add(Quantity::of(2, 'box'));
    }

    #[Test]
    public function quantity_units_are_compared_byte_for_byte(): void
    {
        // Units are opaque identifiers and are never locale-lowercased.
        $this->expectException(InvalidValueObject::class);

        Quantity::of(1, 'Tablet')->add(Quantity::of(1, 'tablet'));
    }

    #[Test]
    public function quantity_allows_negative_intermediate_values(): void
    {
        // The stock ledger is append-only and records movements in both
        // directions. Whether a resulting balance may go negative is an
        // Inventory rule, not a Quantity rule.
        $result = Quantity::of(2, 'tablet')->subtract(Quantity::of(5, 'tablet'));

        $this->assertSame(-3, $result->value);
        $this->assertTrue($result->isNegative());
    }

    // ------------------------------------------------------ Cairo and DST

    #[Test]
    public function the_clock_reports_utc(): void
    {
        $clock = new SystemClock('Africa/Cairo');

        $this->assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    #[Test]
    public function it_converts_utc_to_cairo_across_the_dst_boundary(): void
    {
        $clock = new SystemClock('Africa/Cairo');
        $utc = new DateTimeZone('UTC');

        // Egypt observes DST. A fixed "+02:00" offset is wrong for part of the
        // year, which is exactly why schedules keep an IANA identifier rather
        // than an offset (plan.md section 106).
        $winter = $clock->toBusinessTime(new DateTimeImmutable('2026-01-15T12:00:00', $utc));
        $summer = $clock->toBusinessTime(new DateTimeImmutable('2026-07-15T12:00:00', $utc));

        $this->assertSame('+02:00', $winter->format('P'), 'Cairo winter offset should be +02:00.');
        $this->assertSame('+03:00', $summer->format('P'), 'Cairo summer offset should be +03:00.');

        // The underlying instant is unchanged; only its presentation differs.
        $this->assertSame(
            (new DateTimeImmutable('2026-07-15T12:00:00', $utc))->getTimestamp(),
            $summer->getTimestamp(),
        );
    }

    #[Test]
    public function the_business_timezone_is_configurable_not_hard_coded(): void
    {
        // V1 is Egypt only, but callers ask rather than assume, so a second
        // country stays a configuration change (plan.md section 149).
        $this->assertSame('Africa/Cairo', (new SystemClock('Africa/Cairo'))->businessTimeZone()->getName());
        $this->assertSame('UTC', (new SystemClock('UTC'))->businessTimeZone()->getName());
    }

    #[Test]
    public function country_code_supplies_egypt_defaults(): void
    {
        $this->assertSame(Currency::EGP, CountryCode::EG->defaultCurrency());
        $this->assertSame('Africa/Cairo', CountryCode::EG->timeZoneIdentifier());
        $this->assertSame(2, Currency::EGP->minorUnitScale());
    }

    // ---------------------------------------------------- classification

    #[Test]
    public function classification_forbids_personal_data_in_telemetry(): void
    {
        $this->assertTrue(Classification::Public->allowedInTelemetry());
        $this->assertTrue(Classification::Internal->allowedInTelemetry());

        // Invariant 18. These three must never be emitted as values.
        $this->assertFalse(Classification::Personal->allowedInTelemetry());
        $this->assertFalse(Classification::Sensitive->allowedInTelemetry());
        $this->assertFalse(Classification::Credential->allowedInTelemetry());
    }

    #[Test]
    public function classification_forbids_personal_data_as_metric_labels(): void
    {
        $this->assertTrue(Classification::Internal->allowedAsMetricLabel());
        $this->assertFalse(Classification::Personal->allowedAsMetricLabel());
        $this->assertFalse(Classification::Sensitive->allowedAsMetricLabel());
    }

    #[Test]
    public function classification_forbids_caching_phi_by_default(): void
    {
        // ADR 0007: PHI caching is avoided; an exception requires review,
        // encryption, a bounded TTL, and proven invalidation.
        $this->assertTrue(Classification::Public->cacheableByDefault());
        $this->assertFalse(Classification::Sensitive->cacheableByDefault());
        $this->assertFalse(Classification::Credential->cacheableByDefault());
    }

    #[Test]
    public function classification_levels_are_ordered(): void
    {
        $this->assertTrue(Classification::Sensitive->isAtLeast(Classification::Personal));
        $this->assertTrue(Classification::Credential->isAtLeast(Classification::Sensitive));
        $this->assertFalse(Classification::Internal->isAtLeast(Classification::Personal));
    }
}
