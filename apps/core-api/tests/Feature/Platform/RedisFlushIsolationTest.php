<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Platform\Services\Cache\CacheWarmer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Redis flush loses no authoritative record (G-04-06, ADR 0007).
 */
final class RedisFlushIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function flushing_redis_leaves_postgres_rows_and_cache_can_be_rewarmed(): void
    {
        try {
            Redis::connection('cache')->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Redis is not reachable.');
        }

        DB::table('platform_diagnostics')->insert([
            'id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
            'label' => 'redis-flush-probe',
            'echo_delay_ms' => 0,
            'outbox_event_id' => null,
            'correlation_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02',
            'recorded_at' => '2026-08-26T00:00:00.000000+00:00',
        ]);

        $warmer = new CacheWarmer($this->app->make('cache')->store('redis'), '0.0.0-test');
        $warmer->warm();
        $this->assertSame('0.0.0-test', $warmer->version());

        Redis::connection('cache')->flushdb();

        $this->assertSame(1, DB::table('platform_diagnostics')->where('label', 'redis-flush-probe')->count());
        $this->assertNull($warmer->version());

        $warmer->warm();
        $this->assertSame('0.0.0-test', $warmer->version());
    }
}
