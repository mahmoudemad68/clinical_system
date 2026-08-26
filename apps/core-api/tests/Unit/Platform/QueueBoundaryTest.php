<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 00 queue ownership: Horizon consumes Laravel jobs only. Python workers
 * never deserialize PHP payloads (ADR 0009).
 */
final class QueueBoundaryTest extends TestCase
{
    #[Test]
    public function horizon_lanes_are_php_owned_and_exclude_python_broker_names(): void
    {
        $defaults = config('horizon.defaults');
        $this->assertIsArray($defaults);

        $queues = [];

        foreach ($defaults as $supervisor) {
            $this->assertIsArray($supervisor);
            $this->assertSame('redis', $supervisor['connection']);
            $this->assertIsArray($supervisor['queue']);
            foreach ($supervisor['queue'] as $queue) {
                $queues[] = $queue;
            }
        }

        $this->assertSame(
            [
                'critical',
                'notifications',
                'files',
                'integrations',
                'analytics',
                'reports',
                'backups',
                'ai-orchestration',
            ],
            array_values(array_unique($queues)),
        );

        foreach (['dramatiq', 'celery', 'rq', 'arq', 'ai'] as $pythonName) {
            $this->assertNotContains($pythonName, $queues);
        }

        $this->assertSame('queue', config('horizon.use'));
    }

    #[Test]
    public function an_ai_internal_command_is_not_a_php_serialized_job(): void
    {
        $command = json_encode([
            'command_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
            'command_type' => 'phase00.ping',
            'schema_version' => 1,
            'idempotency_key' => 'phase00-command-key-1',
            'deadline_at' => '2026-08-26T00:00:00Z',
            'correlation_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02',
            'payload' => ['scope' => 'phase00'],
        ], JSON_THROW_ON_ERROR);

        $this->assertFalse(@unserialize($command, ['allowed_classes' => false]));
    }
}
