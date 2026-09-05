<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Identity\Console\RotateIdentityKeysCommand;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Identity\Contracts\PatientSubjectPrivacy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Services\Adapters\UnavailablePatientIdentityRegistry;
use Modules\Identity\Services\Adapters\UnavailablePatientSubjectPrivacy;
use Modules\Identity\Services\AuditedSensitiveDecryptor;
use Modules\Identity\Services\DisableIdentityService;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Services\ExportSubjectDataService;
use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Identity\Services\MeQuery;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Services\Persistence\PostgresIdentityStore;
use Modules\Identity\Services\ResolveActorContext;
use Modules\Identity\Services\RotateIdentityKeysService;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Contracts\HmacHasher;

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

        // Patients (priority 50) replaces this with PostgresPatientIdentityRegistry.
        $this->app->singleton(PatientIdentityRegistry::class, UnavailablePatientIdentityRegistry::class);
        $this->app->singleton(PatientSubjectPrivacy::class, UnavailablePatientSubjectPrivacy::class);

        $this->app->bind(ResolveActorContext::class);
        $this->app->bind(MeQuery::class);
        $this->app->bind(DisableIdentityService::class);
        $this->app->bind(EraseSubjectService::class);
        $this->app->bind(ExportSubjectDataService::class);
        $this->app->bind(LinkVerifiedPatientAccount::class);
        $this->app->bind(AuditedSensitiveDecryptor::class);
        $this->app->bind(RotateIdentityKeysService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RotateIdentityKeysCommand::class]);
        }
    }
}
