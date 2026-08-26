<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('keeps Horizon lanes PHP-owned and excludes Python broker names', function () {
    $defaults = config('horizon.defaults');
    expect($defaults)->toBeArray();

    $queues = [];

    foreach ($defaults as $supervisor) {
        expect($supervisor)->toBeArray()
            ->and($supervisor['connection'])->toBe('redis')
            ->and($supervisor['queue'])->toBeArray();
        foreach ($supervisor['queue'] as $queue) {
            $queues[] = $queue;
        }
    }

    expect(array_values(array_unique($queues)))->toBe([
        'critical',
        'notifications',
        'files',
        'integrations',
        'analytics',
        'reports',
        'backups',
        'ai-orchestration',
    ]);

    foreach (['dramatiq', 'celery', 'rq', 'arq', 'ai'] as $pythonName) {
        expect($queues)->not->toContain($pythonName);
    }

    expect(config('horizon.use'))->toBe('queue');
});

it('does not treat an AI internal command as a PHP serialized job', function () {
    $command = json_encode([
        'command_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
        'command_type' => 'phase00.ping',
        'schema_version' => 1,
        'idempotency_key' => 'phase00-command-key-1',
        'deadline_at' => '2026-08-26T00:00:00Z',
        'correlation_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02',
        'payload' => ['scope' => 'phase00'],
    ], JSON_THROW_ON_ERROR);

    expect(@unserialize($command, ['allowed_classes' => false]))->toBeFalse();
});
