<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Providers;

use App\Modules\Platform\Application\Health\HealthProbeClient;
use App\Modules\Platform\Domain\Contracts\CorrelationScope;
use App\Modules\Platform\Domain\Contracts\DiagnosticsRepository;
use App\Modules\Platform\Application\Health\ReadinessProbe;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdempotencyStore;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\OutboxRecorder;
use App\Modules\Platform\Domain\Contracts\Redactor;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Http\Controllers\OperationalController;
use App\Modules\Platform\Http\Controllers\PlatformHealthController;
use App\Modules\Platform\Infrastructure\Health\AiServiceCheck;
use App\Modules\Platform\Infrastructure\Health\ConfigurationCheck;
use App\Modules\Platform\Infrastructure\Health\DatabaseCheck;
use App\Modules\Platform\Infrastructure\Health\HttpHealthProbeClient;
use App\Modules\Platform\Infrastructure\Health\RedisCheck;
use App\Modules\Platform\Infrastructure\Identity\UuidV7Generator;
use App\Modules\Platform\Infrastructure\Persistence\EloquentDiagnosticsRepository;
use App\Modules\Platform\Infrastructure\Persistence\EloquentIdempotencyStore;
use App\Modules\Platform\Infrastructure\Persistence\EloquentOutboxRecorder;
use App\Modules\Platform\Infrastructure\Telemetry\PatternRedactor;
use App\Modules\Platform\Infrastructure\Time\SystemClock;
use App\Modules\Platform\Infrastructure\Transaction\CorrelationIdProvider;
use App\Modules\Platform\Infrastructure\Transaction\DatabaseTransactionRunner;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Platform shared kernel.
 *
 * Every binding maps a domain-owned interface to an infrastructure
 * implementation, which is the dependency inversion the module layout requires:
 * application and domain code own the interfaces, framework and provider code
 * depends on them.
 *
 * Nothing here contains a business rule. Platform is the one module others may
 * import precisely because it has none (module catalog).
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, static fn (): Clock => new SystemClock(
            (string) config('app.business_timezone', 'Africa/Cairo'),
        ));

        $this->app->singleton(IdentityGenerator::class, UuidV7Generator::class);

        $this->app->singleton(Redactor::class, PatternRedactor::class);

        // Request-scoped under Octane. The Octane hooks in bootstrap reset this
        // between requests; without that, a long-lived worker would carry one
        // request's correlation id into the next (gate G-02-05).
        $this->app->singleton(CorrelationIdProvider::class);
        $this->app->alias(CorrelationIdProvider::class, CorrelationScope::class);

        $this->app->singleton(OutboxRecorder::class, static fn ($app): OutboxRecorder => new EloquentOutboxRecorder(
            DB::connection(),
            $app->make(IdentityGenerator::class),
        ));

        $this->app->singleton(TransactionRunner::class, static fn ($app): TransactionRunner => new DatabaseTransactionRunner(
            DB::connection(),
            $app->make(OutboxRecorder::class),
            $app->make(CorrelationScope::class),
        ));

        $this->app->singleton(IdempotencyStore::class, static fn ($app): IdempotencyStore => new EloquentIdempotencyStore(
            DB::connection(),
            $app->make(Clock::class),
            (int) config('platform.idempotency.retention_hours', 72),
        ));

        $this->app->singleton(DiagnosticsRepository::class, static fn (): DiagnosticsRepository => new EloquentDiagnosticsRepository(
            DB::connection(),
        ));

        $this->app->bind(ConnectionInterface::class, static fn (): ConnectionInterface => DB::connection());

        // The AI probe is bound even when the AI service is absent. It answers
        // "not live", which is a degraded optional dependency, not an error.
        $this->app->singleton(HealthProbeClient::class, static fn ($app): HealthProbeClient => new HttpHealthProbeClient(
            $app->make(HttpFactory::class),
            (string) config('platform.ai.base_url', ''),
            (int) config('platform.ai.timeout_ms', 2000),
        ));

        // Readiness is a list of checks rather than a hard-coded sequence, so
        // adding a dependency is a registration and the critical/optional
        // policy stays in one place.
        $this->app->singleton(ReadinessProbe::class, static fn ($app): ReadinessProbe => new ReadinessProbe(
            [
                new ConfigurationCheck(['app.key', 'app.version', 'database.default']),
                new DatabaseCheck(DB::connection()),
                new RedisCheck($app->make(RedisFactory::class), 'cache'),
                new RedisCheck($app->make(RedisFactory::class), 'queue'),
                // Optional by default. This is the isolation proof: an AI
                // outage reports degraded and core stays ready (gate G-02-04).
                new AiServiceCheck(
                    $app->make(HealthProbeClient::class),
                    (bool) config('platform.ai.required_for_readiness', false),
                ),
            ],
            (string) config('app.version', '0.0.0'),
        ));

        $this->app->bind(OperationalController::class, static fn ($app): OperationalController => new OperationalController(
            $app->make(ReadinessProbe::class),
            (string) config('app.version', '0.0.0'),
        ));

        $this->app->bind(PlatformHealthController::class, static fn ($app): PlatformHealthController => new PlatformHealthController(
            $app->make(ReadinessProbe::class),
            $app->make(Clock::class),
            (string) config('app.version', '0.0.0'),
            (string) config('app.env', 'production'),
            config('app.build_commit'),
            config('app.build_timestamp'),
        ));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(base_path('lang'), '');
    }
}
