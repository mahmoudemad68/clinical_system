<?php

declare(strict_types=1);

namespace Modules\Clinics\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Clinics\Http\Controllers\ClinicLocationController;
use Modules\Clinics\Http\Controllers\ClinicStaffInvitationController;
use Modules\Clinics\Services\AcceptClinicStaffInvitation;
use Modules\Clinics\Services\Adapters\PostgresClinicSubjectPrivacy;
use Modules\Clinics\Services\CreateClinicLocation;
use Modules\Clinics\Services\GetClinicLocation;
use Modules\Clinics\Services\GetOwnClinicLocations;
use Modules\Clinics\Services\InviteClinicStaff;
use Modules\Clinics\Services\ManageClinicMemberships;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Services\ResolveActiveClinicMembership;
use Modules\Clinics\Services\UpdateClinicLocation;
use Modules\Clinics\Support\ClinicLocationProjector;
use Modules\Clinics\Support\ClinicLocationRowFactory;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Identity\Contracts\ClinicSubjectPrivacy;

final class ClinicsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/config.php', 'clinics_module');

        $this->app->singleton(PostgresClinicStore::class, static fn ($app): PostgresClinicStore => new PostgresClinicStore(
            $app->make(ConnectionInterface::class),
        ));

        $this->app->singleton(ClinicSubjectPrivacy::class, PostgresClinicSubjectPrivacy::class);

        $this->app->bind(ClinicLocationProjector::class);
        $this->app->bind(ClinicLocationRowFactory::class);
        $this->app->bind(ClinicOwnerGuard::class);
        $this->app->bind(CreateClinicLocation::class);
        $this->app->bind(UpdateClinicLocation::class);
        $this->app->bind(GetOwnClinicLocations::class);
        $this->app->bind(InviteClinicStaff::class);
        $this->app->bind(AcceptClinicStaffInvitation::class);
        $this->app->bind(ManageClinicMemberships::class);
        $this->app->bind(GetClinicLocation::class);
        $this->app->bind(ResolveActiveClinicMembership::class);
        $this->app->bind(ClinicLocationController::class);
        $this->app->bind(ClinicStaffInvitationController::class);
    }
}
