<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Audit\Infrastructure\Persistence\PostgresAuditStore;
use App\Modules\Identity\Application\DisableIdentityCoordinator;
use App\Modules\Identity\Application\LinkVerifiedPatientAccount;
use App\Modules\Identity\Application\MeQuery;
use App\Modules\Identity\Application\ResolveActorContext;
use App\Modules\Identity\Domain\Contracts\PatientIdentityRegistry;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Infrastructure\Adapters\UnavailablePatientIdentityRegistry;
use App\Modules\Identity\Infrastructure\Console\RotateIdentityKeysCommand;
use App\Modules\Identity\Infrastructure\Persistence\PostgresIdentityStore;
use App\Modules\Platform\Domain\Contracts\FieldEncryptor;
use App\Modules\Platform\Domain\Contracts\HmacHasher;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserDirectory::class, static fn ($app): UserDirectory => new PostgresIdentityStore(
            $app->make(ConnectionInterface::class),
        ));

        $this->app->singleton(NationalIdProtector::class, static fn ($app): NationalIdProtector => new NationalIdProtector(
            $app->make(FieldEncryptor::class),
            $app->make(HmacHasher::class),
            (bool) config('identity.allow_synthetic_national_ids', false),
        ));

        $this->app->singleton(PatientIdentityRegistry::class, UnavailablePatientIdentityRegistry::class);
        $this->app->singleton(AppendAuditEvent::class, static fn ($app): AppendAuditEvent => new PostgresAuditStore(
            $app->make(ConnectionInterface::class),
            $app->make(IdentityGenerator::class),
        ));

        $this->app->bind(ResolveActorContext::class);
        $this->app->bind(MeQuery::class);
        $this->app->bind(DisableIdentityCoordinator::class);
        $this->app->bind(LinkVerifiedPatientAccount::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RotateIdentityKeysCommand::class]);
        }
    }
}
