<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Domain\Contracts\Redactor;
use App\Modules\Platform\Domain\Exceptions\RedactionFailure;
use App\Modules\Platform\Infrastructure\Telemetry\PatternRedactor;
use App\Modules\Platform\Infrastructure\Telemetry\TelemetryGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Export-path canaries (G-07-05). Redaction happens before a payload is
 * treated as having left the process.
 */
final class ExportRedactionTest extends TestCase
{
    #[Test]
    public function a_synthetic_clinical_looking_payload_is_scrubbed_before_export(): void
    {
        $gateway = new TelemetryGateway(new PatternRedactor, true, 'core-api', 'test');

        $redacted = $gateway->captureExport([
            'message' => 'Patient presented with 29999999999999 on file, call 01099999999',
            'context' => [
                'password' => 'not-a-real-password',
                'object_key' => 'clinic/private/abc',
                'request_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
            ],
        ]);

        $serialized = json_encode($redacted, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('29999999999999', $serialized);
        $this->assertStringNotContainsString('01099999999', $serialized);
        $this->assertStringNotContainsString('not-a-real-password', $serialized);
        $this->assertStringContainsString('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01', $serialized);
        $this->assertSame([$redacted], $gateway->snapshots());
    }

    #[Test]
    public function a_passthrough_redactor_fails_closed_on_the_export_path(): void
    {
        $passthrough = new class implements Redactor
        {
            public function redactArray(array $payload): array
            {
                return $payload;
            }

            public function redactText(string $text): string
            {
                return $text;
            }

            public function isSensitiveKey(string $key): bool
            {
                return false;
            }

            public function containsSensitiveValue(string $text): bool
            {
                return str_contains($text, '29999999999999');
            }
        };

        $gateway = new TelemetryGateway($passthrough, true, 'core-api', 'test');

        $this->expectException(RedactionFailure::class);

        $gateway->captureExport(['note' => 'Patient presented with 29999999999999']);
    }

    #[Test]
    public function http_spans_drop_unbounded_attributes(): void
    {
        $gateway = new TelemetryGateway(new PatternRedactor, true, 'core-api', 'test');

        $gateway->startHttpSpan('http.server', [
            'method' => 'GET',
            'route' => 'api.v1.health',
            'status' => '200',
            'patient_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
            'note' => 'chest pain',
        ]);

        $spans = $gateway->httpSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('GET', $spans[0]['method']);
        $this->assertSame('api.v1.health', $spans[0]['route']);
        $this->assertSame('200', $spans[0]['status']);
        $this->assertArrayNotHasKey('patient_id', $spans[0]);
        $this->assertArrayNotHasKey('note', $spans[0]);

        $serialized = json_encode($spans, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('chest pain', $serialized);
        $this->assertStringNotContainsString('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01', $serialized);
        $this->assertTrue($gateway->forceFlush());
    }
}
