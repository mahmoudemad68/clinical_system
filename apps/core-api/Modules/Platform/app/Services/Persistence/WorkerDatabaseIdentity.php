<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Persistence;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Modules\Platform\Contracts\DiagnosticsRepository;
use Modules\Platform\Contracts\IdempotencyStore;
use Modules\Platform\Contracts\OutboxRecorder;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Services\Audit\ConfigChangeAuditor;
use Modules\Platform\Services\Health\ReadinessProbe;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use RuntimeException;
use Throwable;

/**
 * Binds queue/Horizon/outbox worker processes to the clinic_worker PostgreSQL role.
 *
 * HTTP and Octane keep the default `pgsql` connection (clinic_app in serving
 * environments). Worker processes must not inherit that identity: clinic_app
 * still has identity DML, and the threat model says workers consume as
 * clinic_worker. Privilege grants alone do not enforce that — the runtime
 * connection must be pgsql_worker.
 *
 * Activation is fail-closed. A worker whose current_user is clinic_app, or
 * whose worker username is configured to the serving role, does not start.
 */
final class WorkerDatabaseIdentity
{
    public const CONNECTION = 'pgsql_worker';

    public const ROLE = 'clinic_worker';

    public const SERVING_CONNECTION = 'pgsql';

    public const SERVING_ROLE = 'clinic_app';

    /**
     * Artisan commands that are background workers, not HTTP or one-shot operators.
     *
     * @var list<string>
     */
    private const WORKER_COMMANDS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'horizon:listen',
        'outbox:work',
    ];

    /**
     * Singletons that captured ConnectionInterface at first resolve.
     *
     * @var list<class-string>
     */
    private const CAPTURED_CONNECTION_ABSTRACTS = [
        ConnectionInterface::class,
        OutboxRecorder::class,
        TransactionRunner::class,
        IdempotencyStore::class,
        DiagnosticsRepository::class,
        RecordInboxNotification::class,
        ConfigChangeAuditor::class,
        OutboxDispatcher::class,
        ReadinessProbe::class,
    ];

    private bool $active = false;

    private ?string $verifiedRole = null;

    private string $originalDefault = self::SERVING_CONNECTION;

    /**
     * @var array<string, mixed>
     */
    private array $servingConnectionSnapshot = [];

    public function __construct(
        private readonly Application $app,
        private readonly DatabaseManager $db,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }

    public function lastVerifiedRole(): ?string
    {
        return $this->verifiedRole;
    }

    public function isWorkerCommand(string $command): bool
    {
        return in_array($command, self::WORKER_COMMANDS, true);
    }

    public function activateIfConsoleWorkerProcess(): void
    {
        $command = $this->commandFromArgv();

        if ($command === null || ! $this->isWorkerCommand($command)) {
            return;
        }

        $this->activate();
    }

    public function handleCommandStarting(CommandStarting $event): void
    {
        if ($this->isWorkerCommand($event->command)) {
            $this->activate();
        }
    }

    public function handleCommandFinished(CommandFinished $event): void
    {
        if ($this->isWorkerCommand($event->command)) {
            $this->restore();
        }
    }

    public function handleQueueWorkerStarting(): void
    {
        $this->activate();
    }

    public function handleQueueWorkerStopping(): void
    {
        $this->restore();
    }

    public function handleJobProcessing(): void
    {
        if ($this->active) {
            $this->assertWorkerRole();
        }
    }

    public function activate(): void
    {
        if ($this->active) {
            $this->assertWorkerRole();

            return;
        }

        $this->assertConfiguredWorkerRole();
        $this->snapshotServingConnection();

        try {
            $this->switchToWorkerConnection();
            $this->forgetCapturedConnections();
            $this->assertWorkerRole();
            $this->active = true;
        } catch (Throwable $e) {
            $this->restoreServingConnection();
            $this->forgetCapturedConnections();
            $this->active = false;

            throw $e;
        }
    }

    public function restore(): void
    {
        if (! $this->active && $this->servingConnectionSnapshot === []) {
            return;
        }

        $this->restoreServingConnection();
        $this->forgetCapturedConnections();
        $this->active = false;
    }

    public function currentRole(?string $connection = null): string
    {
        $name = $connection ?? $this->db->getDefaultConnection();
        $row = $this->db->connection($name)->selectOne('select current_user as username');

        return $this->usernameFromRow($row);
    }

    private function assertConfiguredWorkerRole(): void
    {
        $workerUser = (string) $this->app['config']->get('database.connections.'.self::CONNECTION.'.username', '');
        $workerPassword = (string) $this->app['config']->get('database.connections.'.self::CONNECTION.'.password', '');
        $servingUser = (string) $this->app['config']->get('database.connections.'.self::SERVING_CONNECTION.'.username', '');

        if ($workerUser !== self::ROLE) {
            throw new RuntimeException(
                'Queue worker database username must be '.self::ROLE.'.',
            );
        }

        if ($workerPassword === '') {
            throw new RuntimeException('Queue worker database password is not configured.');
        }

        if ($servingUser === self::ROLE) {
            throw new RuntimeException(
                'HTTP serving database username must not be '.self::ROLE.'.',
            );
        }
    }

    private function switchToWorkerConnection(): void
    {
        $config = $this->app['config'];
        $worker = (array) $config->get('database.connections.'.self::CONNECTION, []);

        $config->set('database.default', self::CONNECTION);
        $config->set('database.connections.'.self::SERVING_CONNECTION.'.username', $worker['username'] ?? self::ROLE);
        $config->set('database.connections.'.self::SERVING_CONNECTION.'.password', $worker['password'] ?? '');
        $config->set('database.connections.'.self::SERVING_CONNECTION.'.url', $worker['url'] ?? null);

        $this->db->purge(self::SERVING_CONNECTION);
        $this->db->purge(self::CONNECTION);
        $this->db->setDefaultConnection(self::CONNECTION);
    }

    private function snapshotServingConnection(): void
    {
        $this->originalDefault = (string) $this->app['config']->get('database.default', self::SERVING_CONNECTION);
        $this->servingConnectionSnapshot = (array) $this->app['config']->get(
            'database.connections.'.self::SERVING_CONNECTION,
            [],
        );
    }

    private function restoreServingConnection(): void
    {
        if ($this->servingConnectionSnapshot === []) {
            return;
        }

        $this->app['config']->set('database.default', $this->originalDefault);
        $this->app['config']->set(
            'database.connections.'.self::SERVING_CONNECTION,
            $this->servingConnectionSnapshot,
        );

        $this->db->purge(self::SERVING_CONNECTION);
        $this->db->purge(self::CONNECTION);
        $this->db->setDefaultConnection($this->originalDefault);

        $this->servingConnectionSnapshot = [];
        $this->originalDefault = self::SERVING_CONNECTION;
    }

    private function assertWorkerRole(): void
    {
        if ($this->db->getDefaultConnection() !== self::CONNECTION) {
            throw new RuntimeException(
                'Queue worker default database connection must be '.self::CONNECTION.'.',
            );
        }

        $defaultRole = $this->currentRole();
        $namedServingRole = $this->currentRole(self::SERVING_CONNECTION);
        $servingSnapshotUser = (string) ($this->servingConnectionSnapshot['username'] ?? '');

        if ($defaultRole === self::SERVING_ROLE || $namedServingRole === self::SERVING_ROLE) {
            throw new RuntimeException(
                'Queue worker connected as '.self::SERVING_ROLE.'.',
            );
        }

        if ($servingSnapshotUser !== '' && ($defaultRole === $servingSnapshotUser || $namedServingRole === $servingSnapshotUser)) {
            throw new RuntimeException(
                'Queue worker silently reused the HTTP serving database identity.',
            );
        }

        if ($defaultRole !== self::ROLE || $namedServingRole !== self::ROLE) {
            throw new RuntimeException(
                'Queue worker current_user must be '.self::ROLE.'.',
            );
        }

        $this->verifiedRole = $defaultRole;
    }

    private function forgetCapturedConnections(): void
    {
        foreach (self::CAPTURED_CONNECTION_ABSTRACTS as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function commandFromArgv(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (! is_array($argv)) {
            return null;
        }

        foreach ($argv as $index => $argument) {
            if (! is_string($argument)) {
                continue;
            }

            if ($argument === 'artisan') {
                $next = $argv[$index + 1] ?? null;
                if (is_string($next) && $next !== '' && ! str_starts_with($next, '-')) {
                    return $next;
                }
            }
        }

        $candidate = $argv[1] ?? null;

        if (is_string($candidate) && $this->isWorkerCommand($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function usernameFromRow(mixed $row): string
    {
        if (is_object($row) && isset($row->username) && is_string($row->username)) {
            return $row->username;
        }

        if (is_array($row) && isset($row['username']) && is_string($row['username'])) {
            return $row['username'];
        }

        throw new RuntimeException('PostgreSQL current_user could not be read.');
    }
}
