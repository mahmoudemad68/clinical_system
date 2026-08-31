<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use InvalidArgumentException;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Bounded metric labels (G-07-03).
 */
final class PlatformMetricsTest extends TestCase
{
    #[Test]
    public function forbidden_identity_labels_are_refused(): void
    {
        $metrics = new PlatformMetrics('core-api', 'test');

        $this->expectException(InvalidArgumentException::class);

        $metrics->increment('clinic_redis_errors_total', ['patient_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01']);
    }

    #[Test]
    public function unknown_label_keys_are_refused(): void
    {
        $metrics = new PlatformMetrics('core-api', 'test');

        $this->expectException(InvalidArgumentException::class);

        $metrics->increment('clinic_redis_errors_total', ['free_text' => 'chest pain']);
    }

    #[Test]
    public function http_samples_render_without_path_segments(): void
    {
        $metrics = new PlatformMetrics('core-api', '0.0.0-test');
        $metrics->recordHttp('GET', 'api.v1.health', 200, 0.012);

        $text = $metrics->render();

        $this->assertStringContainsString('clinic_http_responses_total', $text);
        $this->assertStringContainsString('status="200"', $text);
        $this->assertStringContainsString('status_class="2xx"', $text);
        $this->assertStringNotContainsString('patient', $text);
    }

    #[Test]
    public function query_histogram_buckets_are_cumulative_and_bounded(): void
    {
        $metrics = new PlatformMetrics('core-api', '0.0.0-test');
        $metrics->observeQuery(0.03);

        $text = $metrics->render();

        $this->assertStringContainsString('le="0.05"', $text);
        $this->assertStringContainsString('le="+Inf"', $text);
        $this->assertStringNotContainsString('patient_id', $text);
        $this->assertStringNotContainsString('select', strtolower($text));
    }
}
