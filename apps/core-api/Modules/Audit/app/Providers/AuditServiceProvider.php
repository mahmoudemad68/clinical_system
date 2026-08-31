<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Console\CheckpointAuditChainCommand;
use Modules\Audit\Console\VerifyAuditChainCommand;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\AuditChainCheckpointStore;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Audit\Services\Checkpoint\AuditChainCheckpointVerifier;
use Modules\Audit\Services\Checkpoint\CreateAuditChainCheckpoint;
use Modules\Audit\Services\Checkpoint\Ed25519AuditChainCheckpointSigner;
use Modules\Audit\Services\Checkpoint\FilesystemAuditChainCheckpointStore;
use Modules\Audit\Services\Persistence\PostgresAuditChainVerifier;
use Modules\Audit\Services\Persistence\PostgresAuditStore;
use Modules\Platform\Contracts\IdentityGenerator;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AppendAuditEvent::class, static function ($app): AppendAuditEvent {
            $connection = $app->environment('testing')
                ? $app->make(ConnectionInterface::class)
                : $app->make(DatabaseManager::class)->connection('pgsql_audit');

            return new PostgresAuditStore(
                $connection,
                $app->make(IdentityGenerator::class),
            );
        });

        $this->app->singleton(AuditChainCheckpointStore::class, FilesystemAuditChainCheckpointStore::class);
        $this->app->singleton(Ed25519AuditChainCheckpointSigner::class);
        $this->app->singleton(AuditChainCheckpointVerifier::class);
        $this->app->singleton(CreateAuditChainCheckpoint::class);
        $this->app->singleton(VerifyAuditChain::class, PostgresAuditChainVerifier::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyAuditChainCommand::class,
                CheckpointAuditChainCommand::class,
            ]);
        }
    }
}
