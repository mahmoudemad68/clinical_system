<?php

declare(strict_types=1);

namespace Modules\Doctors\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Doctors\Http\Controllers\DoctorProfileController;
use Modules\Doctors\Services\Adapters\PostgresDoctorSubjectPrivacy;
use Modules\Doctors\Services\GetDoctorProfile;
use Modules\Doctors\Services\ListSpecialties;
use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Services\Persistence\PostgresSpecialtyStore;
use Modules\Doctors\Services\RegisterDoctor;
use Modules\Doctors\Support\DoctorProfileProjector;
use Modules\Doctors\Support\DoctorProfileRowFactory;
use Modules\Identity\Contracts\DoctorSubjectPrivacy;

final class DoctorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/config.php', 'doctors_module');

        $this->app->singleton(PostgresDoctorProfileStore::class, static fn ($app): PostgresDoctorProfileStore => new PostgresDoctorProfileStore(
            $app->make(ConnectionInterface::class),
        ));
        $this->app->singleton(PostgresSpecialtyStore::class, static fn ($app): PostgresSpecialtyStore => new PostgresSpecialtyStore(
            $app->make(ConnectionInterface::class),
        ));

        $this->app->singleton(DoctorSubjectPrivacy::class, PostgresDoctorSubjectPrivacy::class);

        $this->app->bind(DoctorProfileProjector::class);
        $this->app->bind(DoctorProfileRowFactory::class);
        $this->app->bind(RegisterDoctor::class);
        $this->app->bind(GetDoctorProfile::class);
        $this->app->bind(ListSpecialties::class);
        $this->app->bind(DoctorProfileController::class);
    }
}
