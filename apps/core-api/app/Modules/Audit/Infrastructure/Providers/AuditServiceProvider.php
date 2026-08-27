<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Providers;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Audit\Domain\Contracts\VerifyAuditChain;
use App\Modules\Audit\Infrastructure\Console\VerifyAuditChainCommand;
use App\Modules\Audit\Infrastructure\Persistence\PostgresAuditChainVerifier;
use App\Modules\Audit\Infrastructure\Persistence\PostgresAuditStore;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

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
