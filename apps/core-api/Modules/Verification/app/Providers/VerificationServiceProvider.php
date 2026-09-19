<?php

declare(strict_types=1);

namespace Modules\Verification\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Support\BoundedDocumentInspector;
use Modules\Verification\Console\ReconcileVerificationUploadsCommand;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Http\Controllers\DoctorVerificationController;
use Modules\Verification\Http\Controllers\DoctorVerificationUploadController;
use Modules\Verification\Services\Adapters\DisabledTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\Adapters\ProcessingTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\Outbox\VerificationUploadCompletedConsumer;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Services\VerificationUploadProcessor;
use Modules\Verification\Services\VerificationUploadService;
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
        $this->app->singleton(ProcessingTrustedDocumentEvidenceIssuer::class);
        $this->app->singleton(BoundedDocumentInspector::class);
        $this->app->bind(VerificationService::class);
        $this->app->bind(VerificationDocumentService::class);
        $this->app->bind(VerificationUploadService::class);
        $this->app->bind(VerificationUploadProcessor::class);
        $this->app->bind(DoctorVerificationController::class);
        $this->app->bind(DoctorVerificationUploadController::class);
    }

    public function boot(): void
    {
        $this->app->afterResolving(OutboxDispatcher::class, function (OutboxDispatcher $dispatcher): void {
            $dispatcher->register($this->app->make(VerificationUploadCompletedConsumer::class));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileVerificationUploadsCommand::class]);
        }
    }
}
