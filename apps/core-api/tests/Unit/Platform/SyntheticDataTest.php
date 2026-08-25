<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Domain\ValueObjects\CountryCode;
use App\Modules\Platform\Infrastructure\Telemetry\PatternRedactor;
use App\Modules\Platform\Infrastructure\Testing\SyntheticEgyptianData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gate G-05-05: synthetic generators must not accidentally produce known real
 * identities.
 *
 * These tests exist because the failure mode is silent and permanent. A
 * generator that produces plausible national IDs will eventually produce a real
 * one, and it will be committed to version control inside a test fixture where
 * nobody looks at it again.
 */
final class SyntheticDataTest extends TestCase
{
    private SyntheticEgyptianData $data;

    protected function setUp(): void
    {
        $this->data = new SyntheticEgyptianData;
    }

    #[Test]
    public function national_ids_are_structurally_valid(): void
    {
        // Structural validity matters: format validation must be exercisable.
        for ($i = 0; $i < 200; $i++) {
            $id = $this->data->nationalId();

            $this->assertSame(14, strlen($id));
            $this->assertMatchesRegularExpression(CountryCode::EG->nationalIdPattern(), $id);
        }
    }

    #[Test]
    public function national_ids_can_never_be_a_real_identifier(): void
    {
        // The real scheme assigns 2 for the 1900s and 3 for the 2000s. A 9
        // means nothing, so no real person can hold one of these.
        for ($i = 0; $i < 500; $i++) {
            $id = $this->data->nationalId();

            $this->assertSame('9', $id[0], "Generated {$id} could collide with a real identifier.");
            $this->assertTrue($this->data->isProvablySynthetic($id));
            // The date component is impossible as well: month 99, day 99.
            $this->assertSame('999999', substr($id, 1, 6));
        }
    }

    #[Test]
    public function mobile_numbers_use_an_unallocated_prefix(): void
    {
        // Egyptian operators hold 010, 011, 012, and 015. Nothing holds 019, so
        // a test that accidentally sends an SMS cannot reach a real handset.
        $allocated = ['010', '011', '012', '015'];

        for ($i = 0; $i < 200; $i++) {
            $number = $this->data->mobileNumber();

            $this->assertSame(11, strlen($number));
            $this->assertStringStartsWith('019', $number);
            $this->assertNotContains(substr($number, 0, 3), $allocated);
        }
    }

    #[Test]
    public function emails_use_a_reserved_tld(): void
    {
        // RFC 2606 reserves .invalid, which can never resolve.
        for ($i = 0; $i < 50; $i++) {
            $this->assertStringEndsWith('@example.invalid', $this->data->email());
        }
    }

    #[Test]
    public function names_are_obviously_synthetic_in_both_scripts(): void
    {
        // A name in a screenshot or a bug report must be unmistakably fake.
        $name = $this->data->name();

        $this->assertContains($name['given'], ['Test', 'Sample', 'Demo', 'Example', 'Synthetic']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $name['arabic']);
    }

    #[Test]
    public function locations_fall_inside_egypt(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $point = $this->data->locationInEgypt();

            $this->assertGreaterThanOrEqual(22.0, $point['latitude']);
            $this->assertLessThanOrEqual(31.6, $point['latitude']);
            $this->assertGreaterThanOrEqual(25.0, $point['longitude']);
            $this->assertLessThanOrEqual(35.0, $point['longitude']);
        }
    }

    #[Test]
    public function generated_values_vary(): void
    {
        // A generator that returns one value would make every uniqueness
        // constraint in every later test pass for the wrong reason.
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $this->data->nationalId();
        }

        $this->assertGreaterThan(90, count(array_unique($ids)));
    }

    #[Test]
    public function the_redactor_removes_these_synthetic_values(): void
    {
        // The canaries in the redaction suite must remain catchable. If the
        // generator ever drifts to a shape the redactor does not match, the
        // canary suite would silently stop testing what it claims to.
        $redactor = new PatternRedactor;

        $this->assertTrue($redactor->containsSensitiveValue($this->data->nationalId()));
        $this->assertTrue($redactor->containsSensitiveValue($this->data->email()));
    }
}
