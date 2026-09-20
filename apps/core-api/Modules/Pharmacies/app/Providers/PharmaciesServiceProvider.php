<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Identity\Contracts\PharmacySubjectPrivacy;
use Modules\Pharmacies\Http\Controllers\PharmacyOrganizationController;
use Modules\Pharmacies\Services\Adapters\PostgresPharmacySubjectPrivacy;
use Modules\Pharmacies\Services\GetOwnPharmacyOrganization;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Services\PharmacyApplicantService;
use Modules\Pharmacies\Services\PharmacyReviewerService;
use Modules\Pharmacies\Services\RegisterPharmacyOrganization;
use Modules\Pharmacies\Support\PharmacyOrganizationProjector;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;

final class PharmaciesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/config.php', 'pharmacies_module');

        $this->app->singleton(PostgresPharmacyOrganizationStore::class, static fn ($app): PostgresPharmacyOrganizationStore => new PostgresPharmacyOrganizationStore(
            $app->make(ConnectionInterface::class),
        ));

        $this->app->singleton(PharmacySubjectPrivacy::class, PostgresPharmacySubjectPrivacy::class);

        $this->app->bind(PharmacyOrganizationProjector::class);
        $this->app->bind(PharmacyOrganizationRowFactory::class);
        $this->app->bind(RegisterPharmacyOrganization::class);
        $this->app->bind(GetOwnPharmacyOrganization::class);
        $this->app->bind(PharmacyApplicantService::class);
        $this->app->bind(PharmacyReviewerService::class);
        $this->app->bind(PharmacyOrganizationController::class);
    }
}
