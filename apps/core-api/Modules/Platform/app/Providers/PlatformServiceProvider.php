<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStarting as QueueWorkerStarting;
use Illuminate\Queue\Events\WorkerStopping as QueueWorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Pennant\Events\FeatureUpdated;
use Laravel\Pennant\Feature;
use Modules\Platform\Console\CacheWarmCommand;
use Modules\Platform\Console\OutboxWorkCommand;
use Modules\Platform\Console\PlatformPruneCommand;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\CorrelationScope;
use Modules\Platform\Contracts\CursorSigner;
use Modules\Platform\Contracts\DiagnosticsRepository;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Contracts\GenerateText;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\IdempotencyStore;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\OutboxRecorder;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\Redactor;
use Modules\Platform\Contracts\RetrieveKnowledge;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\SendOtp;
use Modules\Platform\Contracts\SendPush;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Http\Controllers\DiagnosticsController;
use Modules\Platform\Http\Controllers\OperationalController;
use Modules\Platform\Http\Controllers\PlatformHealthController;
use Modules\Platform\Http\Middleware\EnforceIdempotency;
use Modules\Platform\Http\Middleware\EnforceRequestBounds;
use Modules\Platform\Http\Middleware\InstrumentHttp;
use Modules\Platform\Http\Middleware\RequireDiagnosticsSlice;
use Modules\Platform\Http\Middleware\ResolveLocale;
use Modules\Platform\Http\Middleware\SecureResponseHeaders;
use Modules\Platform\Services\Adapters\DisabledGenerateText;
use Modules\Platform\Services\Adapters\DisabledRetrieveKnowledge;
use Modules\Platform\Services\Adapters\DisabledScanObject;
use Modules\Platform\Services\Adapters\DisabledSendOtp;
use Modules\Platform\Services\Adapters\DisabledSendPush;
use Modules\Platform\Services\Adapters\FirebaseSendPush;
use Modules\Platform\Services\Audit\ConfigChangeAuditor;
use Modules\Platform\Services\Cache\CacheWarmer;
use Modules\Platform\Services\Crypto\AesGcmEnvelopeEncryptor;
use Modules\Platform\Services\Crypto\HkdfHmacHasher;
use Modules\Platform\Services\Diagnostics\RecordRoundTripService;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Services\Health\AiServiceCheck;
use Modules\Platform\Services\Health\ConfigurationCheck;
use Modules\Platform\Services\Health\DatabaseCheck;
use Modules\Platform\Services\Health\HealthProbeClient;
use Modules\Platform\Services\Health\HttpHealthProbeClient;
use Modules\Platform\Services\Health\ReadinessProbe;
use Modules\Platform\Services\Health\RedisCheck;
use Modules\Platform\Services\Idempotency\CanonicalRequestHasher;
use Modules\Platform\Services\Identity\UuidV7Generator;
use Modules\Platform\Services\Notifications\LaravelDatabaseInbox;
use Modules\Platform\Services\ObjectStorage\InMemoryStoreObject;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use Modules\Platform\Services\Outbox\DiagnosticsRoundTripConsumer;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Outbox\RetryPolicy;
use Modules\Platform\Services\Pagination\HmacCursorSigner;
use Modules\Platform\Services\Persistence\EloquentDiagnosticsRepository;
use Modules\Platform\Services\Persistence\EloquentIdempotencyStore;
use Modules\Platform\Services\Persistence\EloquentOutboxRecorder;
use Modules\Platform\Services\Persistence\WorkerDatabaseIdentity;
use Modules\Platform\Services\Status\PlatformStatusQuery;
use Modules\Platform\Services\Telemetry\AuditChainVerificationTelemetry;
use Modules\Platform\Services\Telemetry\HttpInstrumentation;
use Modules\Platform\Services\Telemetry\MetricsExposition;
use Modules\Platform\Services\Telemetry\MetricsRenderer;
use Modules\Platform\Services\Telemetry\PatternRedactor;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Services\Telemetry\RecordingHttpInstrumentation;
use Modules\Platform\Services\Telemetry\TelemetryGateway;
use Modules\Platform\Services\Time\SystemClock;
use Modules\Platform\Services\Transaction\CorrelationIdProvider;
use Modules\Platform\Services\Transaction\DatabaseTransactionRunner;
use Psr\Log\LoggerInterface;

/**
 * Wires the Platform shared kernel.
 *
 * Bindings map replaceable contracts (clock, storage, encryption, outbox) to
 * module services. Nothing here contains a business rule. Platform is the one
 * module others may import precisely because it has none (module catalog).
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkerDatabaseIdentity::class);

        $this->app->booting(function (): void {
            $this->app->make(WorkerDatabaseIdentity::class)->activateIfConsoleWorkerProcess();
        });

        $this->app->singleton(Clock::class, static fn (): Clock => new SystemClock(
            (string) config('app.business_timezone', 'Africa/Cairo'),
        ));

        $this->app->singleton(IdentityGenerator::class, UuidV7Generator::class);

        $this->app->singleton(Redactor::class, PatternRedactor::class);

        $this->app->singleton(FieldEncryptor::class, static fn (): FieldEncryptor => new AesGcmEnvelopeEncryptor(
            array_map(static fn (mixed $key): string => (string) $key, (array) config('identity.encryption.keys', [])),
            (int) config('identity.encryption.current_version', 1),
            (int) config('identity.encryption.min_key_length', 32),
        ));

        $this->app->singleton(HmacHasher::class, static fn (): HmacHasher => new HkdfHmacHasher(
            array_map(static fn (mixed $key): string => (string) $key, (array) config('identity.hmac.keys', [])),
            (int) config('identity.hmac.current_version', 1),
            (int) config('identity.hmac.min_key_length', 32),
        ));

        $this->app->singleton(TelemetryGateway::class, static function ($app): TelemetryGateway {
            return new TelemetryGateway(
                $app->make(Redactor::class),
                (bool) config('platform.telemetry.redaction_strict', false),
                'core-api',
                (string) config('app.version', '0.0.0-dev'),
            );
        });

        $this->app->singleton(PlatformMetrics::class, static fn (): PlatformMetrics => new PlatformMetrics(
            'core-api',
            (string) config('app.version', '0.0.0-dev'),
        ));

        $this->app->singleton(AuditChainVerificationTelemetry::class);
        $this->app->singleton(MetricsRenderer::class);
        $this->app->singleton(MetricsExposition::class, static fn ($app): MetricsExposition => $app->make(MetricsRenderer::class));

        $this->app->singleton(HttpInstrumentation::class, static fn ($app): HttpInstrumentation => new RecordingHttpInstrumentation(
            $app->make(TelemetryGateway::class),
            $app->make(PlatformMetrics::class),
        ));

        $this->app->singleton(CanonicalRequestHasher::class);

        $this->app->singleton(CursorSigner::class, static fn (): CursorSigner => new HmacCursorSigner(
            (string) config('app.key'),
        ));

        $this->app->singleton(SendOtp::class, DisabledSendOtp::class);
        $this->app->singleton(SendPush::class, static function ($app): SendPush {
            $credentials = (string) config('platform.firebase.credentials', '');

            if ($credentials === '') {
                return new DisabledSendPush;
            }

            return new FirebaseSendPush(
                $app->make(Messaging::class),
                $app->make(PlatformMetrics::class),
            );
        });

        $this->app->singleton(RecordInboxNotification::class, static fn ($app): RecordInboxNotification => new LaravelDatabaseInbox(
            DB::connection(),
            $app->make(IdentityGenerator::class),
            $app->make(Clock::class),
        ));

        $this->app->bind(PlatformStatusQuery::class, static fn (): PlatformStatusQuery => new PlatformStatusQuery(
            (string) config('app.version', '0.0.0-dev'),
        ));
        $this->app->singleton(ScanObject::class, DisabledScanObject::class);
        $this->app->singleton(GenerateText::class, DisabledGenerateText::class);
        $this->app->singleton(RetrieveKnowledge::class, DisabledRetrieveKnowledge::class);

        $this->app->singleton(StoreObject::class, static function ($app): StoreObject {
            if ($app->environment('testing')) {
                return new InMemoryStoreObject;
            }

            return new S3StoreObject($app['filesystem']->disk('s3'));
        });

        $this->app->singleton(CacheWarmer::class, static fn ($app): CacheWarmer => new CacheWarmer(
            $app['cache']->store(),
            (string) config('app.version', '0.0.0-dev'),
        ));

        $this->app->singleton(ConfigChangeAuditor::class, static fn ($app): ConfigChangeAuditor => new ConfigChangeAuditor(
            DB::connection(),
            $app->make(IdentityGenerator::class),
            $app->make(Clock::class),
        ));

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
                new ConfigurationCheck([
                    'app.key',
                    'app.version',
                    'database.default',
                    'identity.hmac.keys.1',
                    'identity.encryption.keys.1',
                ]),
                new DatabaseCheck(DB::connection()),
                new RedisCheck($app->make(RedisFactory::class), 'cache', true, $app->make(PlatformMetrics::class)),
                new RedisCheck($app->make(RedisFactory::class), 'queue', true, $app->make(PlatformMetrics::class)),
                new RedisCheck($app->make(RedisFactory::class), 'realtime', true, $app->make(PlatformMetrics::class)),
                new RedisCheck($app->make(RedisFactory::class), 'ratelimit', true, $app->make(PlatformMetrics::class)),
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
            $app->make(MetricsExposition::class),
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
                workerId: substr(gethostname().':'.getmypid(), 0, 64),
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
            config('app.env') === 'local',
        ));

        $this->app->bind(RequireDiagnosticsSlice::class, static fn (): RequireDiagnosticsSlice => new RequireDiagnosticsSlice(
            (bool) config('platform.features.diagnostics_slice', false),
            (string) config('app.env', 'production'),
            (array) config('platform.diagnostics_environments', []),
            (string) config('platform.diagnostics_slice_token', ''),
        ));

        $this->app->bind(EnforceIdempotency::class, static fn ($app): EnforceIdempotency => new EnforceIdempotency(
            $app->make(IdempotencyStore::class),
            $app->make(CanonicalRequestHasher::class),
        ));

        $this->app->bind(InstrumentHttp::class, static fn ($app): InstrumentHttp => new InstrumentHttp(
            $app->make(HttpInstrumentation::class),
        ));

        $this->app->bind(DiagnosticsController::class, static fn ($app): DiagnosticsController => new DiagnosticsController(
            $app->make(RecordRoundTripService::class),
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

        $this->bindWorkerDatabaseIdentity();
        $this->resetRequestScopedStateBetweenOctaneRequests();
        $this->defineFeatureFlags();
        $this->auditFeatureChanges();
        $this->observeDatabaseQueries();

        if ($this->app->runningInConsole()) {
            $this->commands([OutboxWorkCommand::class, PlatformPruneCommand::class, CacheWarmCommand::class]);
        }
    }

    /**
     * Queue/Horizon/outbox processes use clinic_worker; HTTP stays on pgsql.
     *
     * CommandStarting is not dispatched during Pest (Laravel skips Symfony
     * command-event rerouting in unit tests), so OutboxWorkCommand also
     * activates itself. Production `php artisan queue:work` is covered by argv
     * at booting plus Queue WorkerStarting.
     */
    private function bindWorkerDatabaseIdentity(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->app->make(WorkerDatabaseIdentity::class)->handleCommandStarting($event);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            $this->app->make(WorkerDatabaseIdentity::class)->handleCommandFinished($event);
        });

        Event::listen(QueueWorkerStarting::class, function (): void {
            $this->app->make(WorkerDatabaseIdentity::class)->handleQueueWorkerStarting();
        });

        Event::listen(QueueWorkerStopping::class, function (): void {
            $this->app->make(WorkerDatabaseIdentity::class)->handleQueueWorkerStopping();
        });

        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(WorkerDatabaseIdentity::class)->handleJobProcessing();
        });
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
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        $reset = function (object $event): void {
            $container = property_exists($event, 'sandbox') ? $event->sandbox : $this->app;

            if ($container->resolved(CorrelationScope::class)) {
                $container->make(CorrelationScope::class)->reset();
            }
        };

        Event::listen(RequestReceived::class, $reset);
        Event::listen(RequestTerminated::class, $reset);
    }

    private function observeDatabaseQueries(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $this->app->make(PlatformMetrics::class)->observeQuery($query->time / 1000.0);
        });
    }

    private function defineFeatureFlags(): void
    {
        Feature::define(
            PlatformFeatures::DIAGNOSTICS_SLICE,
            static fn (): bool => (bool) config('platform.features.diagnostics_slice', false),
        );

        Feature::define(
            PlatformFeatures::AUTH_REGISTRATION,
            static fn (): bool => PlatformFeatures::enabled(PlatformFeatures::AUTH_REGISTRATION),
        );

        Feature::define(
            PlatformFeatures::IDENTITY_PROFILE_CLAIM,
            static fn (): bool => PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM),
        );

        Feature::define(
            PlatformFeatures::AUTH_RECOVERY,
            static fn (): bool => PlatformFeatures::enabled(PlatformFeatures::AUTH_RECOVERY),
        );

        foreach (PlatformFeatures::V1_EXCLUSIONS as $name) {
            Feature::define($name, static fn (): bool => false);
        }
    }

    private function auditFeatureChanges(): void
    {
        Event::listen(FeatureUpdated::class, function (FeatureUpdated $event): void {
            $value = is_bool($event->value)
                ? ($event->value ? 'true' : 'false')
                : 'defined';

            $this->app->make(ConfigChangeAuditor::class)->record(
                kind: 'flag',
                key: str_replace('_', '-', (string) $event->feature),
                fromValue: null,
                toValue: $value,
            );
        });
    }
}
