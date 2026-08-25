<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Providers;

use App\Modules\Platform\Application\Health\HealthProbeClient;
use App\Modules\Platform\Application\Outbox\RetryPolicy;
use App\Modules\Platform\Infrastructure\Console\OutboxWorkCommand;
use App\Modules\Platform\Infrastructure\Console\PlatformPruneCommand;
use App\Modules\Platform\Infrastructure\Outbox\DiagnosticsRoundTripConsumer;
use App\Modules\Platform\Infrastructure\Outbox\OutboxDispatcher;
use App\Modules\Platform\Domain\Contracts\CorrelationScope;
use App\Modules\Platform\Domain\Contracts\DiagnosticsRepository;
use App\Modules\Platform\Application\Health\ReadinessProbe;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdempotencyStore;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\OutboxRecorder;
use App\Modules\Platform\Domain\Contracts\Redactor;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Http\Controllers\DiagnosticsController;
use App\Modules\Platform\Http\Controllers\OperationalController;
use App\Modules\Platform\Http\Controllers\PlatformHealthController;
use App\Modules\Platform\Http\Middleware\EnforceIdempotency;
use App\Modules\Platform\Http\Middleware\EnforceRequestBounds;
use App\Modules\Platform\Http\Middleware\RequireDiagnosticsSlice;
use App\Modules\Platform\Http\Middleware\ResolveLocale;
use App\Modules\Platform\Http\Middleware\SecureResponseHeaders;
use App\Modules\Platform\Application\Diagnostics\RecordRoundTripHandler;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

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

        // ---------------------------------------------------------------- outbox

        $this->app->singleton(RetryPolicy::class, static fn (): RetryPolicy => new RetryPolicy(
            (int) config('platform.outbox.base_backoff_seconds', 2),
            (int) config('platform.outbox.max_backoff_seconds', 3600),
            (int) config('platform.outbox.max_attempts', 8),
        ));

        $this->app->singleton(OutboxDispatcher::class, static function ($app): OutboxDispatcher {
            $dispatcher = new OutboxDispatcher(
                DB::connection(),
                $app->make(Clock::class),
                $app->make(RetryPolicy::class),
                $app->make(LoggerInterface::class),
                // Worker identity for the claim lease. Host plus pid is enough
                // to tell two workers apart in an operator query.
                workerId: substr(gethostname() . ':' . getmypid(), 0, 64),
                batchSize: (int) config('platform.outbox.claim_batch_size', 100),
                leaseSeconds: (int) config('platform.outbox.lease_seconds', 60),
            );

            // Consumers register here. A published event type with no
            // registered consumer dead-letters rather than retrying forever,
            // so a forgotten registration surfaces as an operator alert.
            $dispatcher->register(new DiagnosticsRoundTripConsumer(
                DB::connection(),
                $app->make(Clock::class),
            ));

            return $dispatcher;
        });

        // ------------------------------------------------------------ middleware
        // Each middleware receives its configuration explicitly rather than
        // calling config() internally, so every one of them is constructible
        // in a unit test without booting the framework.

        $this->app->bind(EnforceRequestBounds::class, static fn (): EnforceRequestBounds => new EnforceRequestBounds(
            (int) config('platform.request.max_body_bytes', 1_048_576),
            (int) config('platform.request.max_json_depth', 32),
        ));

        $this->app->bind(ResolveLocale::class, static fn (): ResolveLocale => new ResolveLocale(
            array_values(array_filter((array) config('app.supported_locales', ['ar', 'en']))),
            (string) config('app.fallback_locale', 'en'),
        ));

        $this->app->bind(SecureResponseHeaders::class, static fn (): SecureResponseHeaders => new SecureResponseHeaders(
            // HSTS only outside local, and only when the request actually
            // arrived over TLS. Pinning localhost to https is hard to undo.
            config('app.env') !== 'local',
        ));

        $this->app->bind(RequireDiagnosticsSlice::class, static fn (): RequireDiagnosticsSlice => new RequireDiagnosticsSlice(
            (bool) config('platform.features.diagnostics_slice', false),
            (string) config('app.env', 'production'),
            (array) config('platform.diagnostics_environments', []),
            (string) env('DIAGNOSTICS_SLICE_TOKEN', ''),
        ));

        $this->app->bind(EnforceIdempotency::class, static fn ($app): EnforceIdempotency => new EnforceIdempotency(
            $app->make(IdempotencyStore::class),
            $app->make(Clock::class),
        ));

        $this->app->bind(DiagnosticsController::class, static fn ($app): DiagnosticsController => new DiagnosticsController(
            $app->make(RecordRoundTripHandler::class),
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

        $this->resetRequestScopedStateBetweenOctaneRequests();

        if ($this->app->runningInConsole()) {
            $this->commands([OutboxWorkCommand::class, PlatformPruneCommand::class]);
        }
    }

    /**
     * Clear request-scoped state on long-lived Octane workers (gate G-02-05).
     *
     * Octane keeps the application container alive across requests. Any
     * singleton holding request state therefore carries it into the next
     * request served by the same worker. For a correlation id that means two
     * unrelated requests appear correlated; for anything actor-scoped it would
     * mean one patient's context bleeding into another's response, which is the
     * failure mode this hook exists to prevent.
     *
     * Both events are registered: RequestReceived clears before handling, and
     * RequestTerminated clears after, so state cannot survive either an early
     * return or an exception mid-request.
     *
     * The listeners are registered only when Octane is present, so the same
     * code runs unchanged under php-fpm and in tests.
     */
    private function resetRequestScopedStateBetweenOctaneRequests(): void
    {
        if (!class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }

        $reset = function (object $event): void {
            $container = property_exists($event, 'sandbox') ? $event->sandbox : $this->app;

            if ($container->resolved(CorrelationScope::class)) {
                $container->make(CorrelationScope::class)->reset();
            }
        };

        Event::listen(\Laravel\Octane\Events\RequestReceived::class, $reset);
        Event::listen(\Laravel\Octane\Events\RequestTerminated::class, $reset);
    }
}
