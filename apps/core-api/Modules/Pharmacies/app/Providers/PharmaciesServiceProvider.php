<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Identity\Contracts\PharmacySubjectPrivacy;
use Modules\Pharmacies\Http\Controllers\PharmacyBranchController;
use Modules\Pharmacies\Http\Controllers\PharmacyOrganizationController;
use Modules\Pharmacies\Http\Controllers\PharmacyStaffInvitationController;
use Modules\Pharmacies\Services\AcceptPharmacyStaffInvitation;
use Modules\Pharmacies\Services\Adapters\PostgresPharmacySubjectPrivacy;
use Modules\Pharmacies\Services\CreatePharmacyBranch;
use Modules\Pharmacies\Services\GetOwnPharmacyBranches;
use Modules\Pharmacies\Services\GetOwnPharmacyOrganization;
use Modules\Pharmacies\Services\InvitePharmacyStaff;
use Modules\Pharmacies\Services\ManagePharmacyMemberships;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Services\PharmacyApplicantService;
use Modules\Pharmacies\Services\PharmacyReviewerService;
use Modules\Pharmacies\Services\RegisterPharmacyOrganization;
use Modules\Pharmacies\Services\ResolveActivePharmacyMembership;
use Modules\Pharmacies\Services\UpdatePharmacyBranch;
use Modules\Pharmacies\Support\PharmacyBranchProjector;
use Modules\Pharmacies\Support\PharmacyOrganizationProjector;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;

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
        $this->app->bind(PharmacyBranchProjector::class);
        $this->app->bind(PharmacyOrganizationRowFactory::class);
        $this->app->bind(PharmacyOwnerGuard::class);
        $this->app->bind(RegisterPharmacyOrganization::class);
        $this->app->bind(GetOwnPharmacyOrganization::class);
        $this->app->bind(CreatePharmacyBranch::class);
        $this->app->bind(UpdatePharmacyBranch::class);
        $this->app->bind(GetOwnPharmacyBranches::class);
        $this->app->bind(InvitePharmacyStaff::class);
        $this->app->bind(AcceptPharmacyStaffInvitation::class);
        $this->app->bind(ManagePharmacyMemberships::class);
        $this->app->bind(ResolveActivePharmacyMembership::class);
        $this->app->bind(PharmacyApplicantService::class);
        $this->app->bind(PharmacyReviewerService::class);
        $this->app->bind(PharmacyOrganizationController::class);
        $this->app->bind(PharmacyBranchController::class);
        $this->app->bind(PharmacyStaffInvitationController::class);
    }
}
