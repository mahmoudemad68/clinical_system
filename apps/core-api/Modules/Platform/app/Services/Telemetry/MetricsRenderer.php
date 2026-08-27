<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Telemetry;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Services\Health\CheckStatus;
use Modules\Platform\Services\Health\ReadinessProbe;
use Throwable;

/**
 * Fills the Prometheus families from live PostgreSQL, Redis, and readiness,
 * then renders text exposition. Scraped on /metrics, not on the public API.
 */
final class MetricsRenderer implements MetricsExposition
{
    /** @var list<string> */
    private const HORIZON_LANES = [
        'critical',
        'notifications',
        'files',
        'integrations',
        'analytics',
        'reports',
        'backups',
        'ai-orchestration',
    ];

    public function __construct(
        private readonly PlatformMetrics $metrics,
        private readonly ReadinessProbe $readiness,
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
        private readonly TelemetryGateway $telemetry,
        private readonly RedisFactory $redis,
    ) {}

    public function render(): string
    {
        $result = $this->readiness->evaluate();
        $this->metrics->set('clinic_readiness_status', $result->ready ? 1.0 : 0.0);

        foreach ($result->checks as $check) {
            $value = match ($check->status) {
                CheckStatus::Pass => 1.0,
                CheckStatus::Degraded => 0.5,
                CheckStatus::Fail => 0.0,
            };
            $this->metrics->set('clinic_dependency_status', $value, ['check' => $check->name]);
        }

        $this->scrapeOutbox();
        $this->scrapeDatabasePool();
        $this->scrapeHorizon();
        $this->metrics->set('clinic_reverb_connections', 0.0);
        $this->metrics->set('clinic_redaction_canary_total', (float) $this->telemetry->canaryDetections, ['rule' => 'export']);

        return $this->metrics->render();
    }

    private function scrapeOutbox(): void
    {
        $pending = (int) $this->connection->table('outbox_events')
            ->whereIn('status', ['PENDING', 'CLAIMED'])
            ->count();
        $dead = (int) $this->connection->table('outbox_events')
            ->where('status', 'DEAD_LETTER')
            ->count();

        $this->metrics->set('clinic_outbox_pending_total', (float) $pending);
        $this->metrics->set('clinic_outbox_dead_letter_total', (float) $dead);

        $oldest = $this->connection->table('outbox_events')
            ->whereIn('status', ['PENDING', 'CLAIMED'])
            ->min('available_at');

        $age = 0.0;

        if (is_string($oldest) && $oldest !== '') {
            $when = new DateTimeImmutable($oldest, new DateTimeZone('UTC'));
            $age = max(0.0, (float) ($this->clock->now()->getTimestamp() - $when->getTimestamp()));
        }

        $this->metrics->set('clinic_outbox_oldest_pending_age_seconds', $age);
    }

    private function scrapeDatabasePool(): void
    {
        try {
            $row = $this->connection->selectOne(
                <<<'SQL'
                select
                    (select count(*) from pg_stat_activity where datname = current_database()) as in_use,
                    (select setting::int from pg_settings where name = 'max_connections') as max_conn,
                    (select rolconnlimit from pg_roles where rolname = current_user) as role_limit
                SQL
            );
        } catch (Throwable) {
            $this->metrics->set('clinic_db_connections_in_use', 0.0);
            $this->metrics->set('clinic_db_connections_limit', 0.0);

            return;
        }

        $inUse = is_object($row) ? (int) ($row->in_use ?? 0) : 0;
        $maxConn = is_object($row) ? (int) ($row->max_conn ?? 0) : 0;
        $roleLimit = is_object($row) ? (int) ($row->role_limit ?? -1) : -1;
        $limit = ($roleLimit > 0) ? min($maxConn, $roleLimit) : $maxConn;

        $this->metrics->set('clinic_db_connections_in_use', (float) $inUse);
        $this->metrics->set('clinic_db_connections_limit', (float) $limit);
    }

    private function scrapeHorizon(): void
    {
        try {
            $connection = $this->redis->connection('queue');
        } catch (Throwable) {
            $this->metrics->increment('clinic_redis_errors_total', ['connection' => 'queue']);

            foreach (self::HORIZON_LANES as $lane) {
                $this->metrics->set('clinic_horizon_queue_depth', 0.0, ['queue' => $lane]);
            }

            return;
        }

        foreach (self::HORIZON_LANES as $lane) {
            try {
                $key = 'queues:'.$lane;
                $waiting = (int) $connection->command('llen', [$key]);
                $delayed = (int) $connection->command('zcard', [$key.':delayed']);
                $reserved = (int) $connection->command('zcard', [$key.':reserved']);
                $this->metrics->set(
                    'clinic_horizon_queue_depth',
                    (float) ($waiting + $delayed + $reserved),
                    ['queue' => $lane],
                );
            } catch (Throwable) {
                $this->metrics->increment('clinic_redis_errors_total', ['connection' => 'queue']);
                $this->metrics->set('clinic_horizon_queue_depth', 0.0, ['queue' => $lane]);
            }
        }
    }
}
