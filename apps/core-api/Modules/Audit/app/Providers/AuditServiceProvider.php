<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Console\VerifyAuditChainCommand;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\VerifyAuditChain;
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

        $this->app->singleton(VerifyAuditChain::class, PostgresAuditChainVerifier::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditChainCommand::class]);
        }
    }
}
