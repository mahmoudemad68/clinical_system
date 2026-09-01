<?php

declare(strict_types=1);

namespace Modules\Patients\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Patients\Http\Controllers\PatientProfileController;
use Modules\Patients\Services\Adapters\PostgresPatientIdentityRegistry;
use Modules\Patients\Services\CreatePatientProfile;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Patients\Services\GetOwnPatientProfile;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Services\ResolvePatientHandle;
use Modules\Patients\Services\UpdateOwnDemographics;
use Modules\Patients\Support\PatientProfileProjector;
use Modules\Patients\Support\PatientProfileRowFactory;

final class PatientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/config.php', 'patients_module');

        $this->app->singleton(PostgresPatientProfileStore::class, static fn ($app): PostgresPatientProfileStore => new PostgresPatientProfileStore(
            $app->make(ConnectionInterface::class),
        ));

        $this->app->singleton(PatientIdentityRegistry::class, PostgresPatientIdentityRegistry::class);

        $this->app->bind(PatientProfileProjector::class);
        $this->app->bind(PatientProfileRowFactory::class);
        $this->app->bind(CreatePatientProfile::class);
        $this->app->bind(GetOwnPatientProfile::class);
        $this->app->bind(UpdateOwnDemographics::class);
        $this->app->bind(CreateUnlinkedPatientProfile::class);
        $this->app->bind(ResolvePatientHandle::class);
        $this->app->bind(PatientProfileController::class);
    }
}
