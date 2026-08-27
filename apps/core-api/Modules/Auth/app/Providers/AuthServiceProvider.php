<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Auth\Console\ApplyDueRecoveriesCommand;
use Modules\Auth\Console\BootstrapAdminCommand;
use Modules\Auth\Console\PruneExpiredAuthStateCommand;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\AuthTelemetry;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Middleware\AuthenticateActor;
use Modules\Auth\Http\Middleware\AuthenticateDevice;
use Modules\Auth\Http\Middleware\DenyPendingBusinessAccess;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Auth\Services\Adapters\DisabledDeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Auth\Services\ApplyRecoveryService;
use Modules\Auth\Services\AuthenticatePasswordService;
use Modules\Auth\Services\AuthRateLimiter;
use Modules\Auth\Services\CompleteMfaService;
use Modules\Auth\Services\CompleteRecoveryService;
use Modules\Auth\Services\ConfirmTotpService;
use Modules\Auth\Services\CredentialIssuer;
use Modules\Auth\Services\Crypto\Argon2idPasswordHasher;
use Modules\Auth\Services\Crypto\OtphpTotpVerifier;
use Modules\Auth\Services\DisableTotpService;
use Modules\Auth\Services\EnrollTotpService;
use Modules\Auth\Services\IssueAuthenticatedSession;
use Modules\Auth\Services\Outbox\OtpDeliveryConsumer;
use Modules\Auth\Services\Outbox\SessionRevokedConsumer;
use Modules\Auth\Services\Persistence\PostgresAuthStore;
use Modules\Auth\Services\RefreshDeviceSessionService;
use Modules\Auth\Services\RegisterAccountService;
use Modules\Auth\Services\RequestOtpService;
use Modules\Auth\Services\RotateRecoveryCodesService;
use Modules\Auth\Services\SessionCommandService;
use Modules\Auth\Services\Telemetry\PrometheusAuthTelemetry;
use Modules\Auth\Services\VerifyOtpService;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Services\Crypto\PhpRandomBytes;
use Modules\Platform\Services\Outbox\OutboxDispatcher;

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

        $this->app->singleton(AuthenticationRateLimiter::class, static function ($app): AuthenticationRateLimiter {
            $store = (string) config('cache.auth_rate_limiter', 'ratelimit');
            $cache = $app->make('cache')->store($store);

            return new AuthRateLimiter(
                new RateLimiter($cache),
                (array) config('identity.rate_limits'),
            );
        });

        $this->app->singleton(AuthTelemetry::class, PrometheusAuthTelemetry::class);

        $this->app->bind(PasswordPolicy::class, static fn (): PasswordPolicy => new PasswordPolicy(
            (int) config('identity.password.min_length', 12),
            (int) config('identity.password.max_length', 128),
        ));

        $this->app->bind(CredentialIssuer::class);
        $this->app->bind(IssueAuthenticatedSession::class);
        $this->app->bind(RegisterAccountService::class);
        $this->app->bind(RequestOtpService::class);
        $this->app->bind(VerifyOtpService::class);
        $this->app->bind(AuthenticatePasswordService::class);
        $this->app->bind(CompleteMfaService::class);
        $this->app->bind(RefreshDeviceSessionService::class);
        $this->app->bind(SessionCommandService::class);
        $this->app->bind(CompleteRecoveryService::class);
        $this->app->bind(ApplyRecoveryService::class);
        $this->app->bind(EnrollTotpService::class);
        $this->app->bind(ConfirmTotpService::class);
        $this->app->bind(RotateRecoveryCodesService::class);
        $this->app->bind(DisableTotpService::class);
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
            $this->commands([
                BootstrapAdminCommand::class,
                PruneExpiredAuthStateCommand::class,
                ApplyDueRecoveriesCommand::class,
            ]);
        }
    }
}
