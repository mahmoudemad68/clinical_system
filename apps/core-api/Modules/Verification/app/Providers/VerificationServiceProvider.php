<?php

declare(strict_types=1);

namespace Modules\Verification\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Http\Controllers\DoctorVerificationController;
use Modules\Verification\Services\Adapters\DisabledTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\VerificationPolicy;

final class VerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/config.php', 'verification_module');

        $this->app->singleton(PostgresVerificationStore::class, static fn ($app): PostgresVerificationStore => new PostgresVerificationStore(
            $app->make(ConnectionInterface::class),
        ));
        $this->app->bind(VerificationPolicy::class);
        $this->app->singleton(TrustedDocumentEvidenceIssuer::class, DisabledTrustedDocumentEvidenceIssuer::class);
        $this->app->bind(VerificationService::class);
        $this->app->bind(VerificationDocumentService::class);
        $this->app->bind(DoctorVerificationController::class);
    }
}
