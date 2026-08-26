<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Providers;

use App\Modules\Auth\Application\AuthenticatePasswordHandler;
use App\Modules\Auth\Application\CompleteMfaHandler;
use App\Modules\Auth\Application\CompleteRecoveryHandler;
use App\Modules\Auth\Application\CredentialIssuer;
use App\Modules\Auth\Application\IssueAuthenticatedSession;
use App\Modules\Auth\Application\RefreshDeviceSessionHandler;
use App\Modules\Auth\Application\RegisterAccountCoordinator;
use App\Modules\Auth\Application\RequestOtpHandler;
use App\Modules\Auth\Application\SessionCommandHandler;
use App\Modules\Auth\Application\VerifyOtpHandler;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthenticationRateLimiter;
use App\Modules\Auth\Domain\Contracts\AuthTelemetry;
use App\Modules\Auth\Domain\Contracts\DeliverOtpSms;
use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use App\Modules\Auth\Domain\Contracts\TotpVerifier;
use App\Modules\Auth\Domain\Rules\PasswordPolicy;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Middleware\AuthenticateActor;
use App\Modules\Auth\Http\Middleware\AuthenticateDevice;
use App\Modules\Auth\Http\Middleware\DenyPendingBusinessAccess;
use App\Modules\Auth\Infrastructure\Adapters\DisabledDeliverOtpSms;
use App\Modules\Auth\Infrastructure\Adapters\RecordingDeliverOtpSms;
use App\Modules\Auth\Infrastructure\AuthRateLimiter;
use App\Modules\Auth\Infrastructure\Console\BootstrapAdminCommand;
use App\Modules\Auth\Infrastructure\Console\PruneExpiredAuthStateCommand;
use App\Modules\Auth\Infrastructure\Crypto\Argon2idPasswordHasher;
use App\Modules\Auth\Infrastructure\Crypto\OtphpTotpVerifier;
use App\Modules\Auth\Infrastructure\Outbox\OtpDeliveryConsumer;
use App\Modules\Auth\Infrastructure\Outbox\SessionRevokedConsumer;
use App\Modules\Auth\Infrastructure\Persistence\PostgresAuthStore;
use App\Modules\Auth\Infrastructure\Telemetry\PrometheusAuthTelemetry;
use App\Modules\Platform\Domain\Contracts\RandomBytes;
use App\Modules\Platform\Infrastructure\Crypto\PhpRandomBytes;
use App\Modules\Platform\Infrastructure\Outbox\OutboxDispatcher;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RandomBytes::class, PhpRandomBytes::class);

        $this->app->singleton(PasswordHasher::class, static fn ($app): PasswordHasher => new Argon2idPasswordHasher(
            $app->make('hash')->driver('argon2id'),
        ));

        $this->app->singleton(TotpVerifier::class, static fn ($app): TotpVerifier => new OtphpTotpVerifier(
            $app->make(RandomBytes::class),
        ));

        $this->app->singleton(DeliverOtpSms::class, static function ($app): DeliverOtpSms {
            if ($app->environment('testing')) {
                return new RecordingDeliverOtpSms;
            }

            return new DisabledDeliverOtpSms;
        });

        $this->app->singleton(AuthDirectory::class, static fn ($app): AuthDirectory => new PostgresAuthStore(
            $app->make(ConnectionInterface::class),
        ));

        $this->app->singleton(AuthenticationRateLimiter::class, static fn ($app): AuthenticationRateLimiter => new AuthRateLimiter(
            $app->make(RateLimiter::class),
            (array) config('identity.rate_limits'),
        ));

        $this->app->singleton(AuthTelemetry::class, PrometheusAuthTelemetry::class);

        $this->app->bind(PasswordPolicy::class, static fn (): PasswordPolicy => new PasswordPolicy(
            (int) config('identity.password.min_length', 12),
            (int) config('identity.password.max_length', 128),
        ));

        $this->app->bind(CredentialIssuer::class);
        $this->app->bind(IssueAuthenticatedSession::class);
        $this->app->bind(RegisterAccountCoordinator::class);
        $this->app->bind(RequestOtpHandler::class);
        $this->app->bind(VerifyOtpHandler::class);
        $this->app->bind(AuthenticatePasswordHandler::class);
        $this->app->bind(CompleteMfaHandler::class);
        $this->app->bind(RefreshDeviceSessionHandler::class);
        $this->app->bind(SessionCommandHandler::class);
        $this->app->bind(CompleteRecoveryHandler::class);
        $this->app->bind(AuthController::class);
        $this->app->bind(AuthenticateDevice::class);
        $this->app->bind(AuthenticateActor::class);
        $this->app->bind(DenyPendingBusinessAccess::class);
    }

    public function boot(): void
    {
        $this->app->make(OutboxDispatcher::class)->register($this->app->make(OtpDeliveryConsumer::class));
        $this->app->make(OutboxDispatcher::class)->register($this->app->make(SessionRevokedConsumer::class));

        if ($this->app->runningInConsole()) {
            $this->commands([BootstrapAdminCommand::class, PruneExpiredAuthStateCommand::class]);
        }
    }
}
