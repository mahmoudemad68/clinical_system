<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Middleware\AssignCorrelationId;
use App\Modules\Platform\Http\Middleware\EnforceIdempotency;
use App\Modules\Platform\Http\Middleware\EnforceRequestBounds;
use App\Modules\Platform\Http\Middleware\InstrumentHttp;
use App\Modules\Platform\Http\Middleware\RequireDiagnosticsSlice;
use App\Modules\Platform\Http\Middleware\ResolveLocale;
use App\Modules\Platform\Http\Middleware\SecureResponseHeaders;
use App\Modules\Platform\Http\Support\ExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        then: function (): void {
            // Operational probes are registered without the api prefix or the
            // api middleware group: an orchestrator must be able to reach them
            // even when the application middleware stack is the thing at fault.
            Route::middleware([])
                ->group(__DIR__.'/../routes/operational.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Global order matters and is the shared request flow from the phase
         * file, steps 2 and 3:
         *
         *   1. correlation id assigned, so even a rejected request is traceable;
         *   2. request bounds enforced before anything parses a body;
         *   3. locale negotiated for safe, localized error messages;
         *   4. secure headers applied to every response on the way out.
         */
        $middleware->api(prepend: [
            HandleCors::class,
            AssignCorrelationId::class,
            InstrumentHttp::class,
            EnforceRequestBounds::class,
            ResolveLocale::class,
            SecureResponseHeaders::class,
        ]);

        $middleware->alias([
            'platform.diagnostics' => RequireDiagnosticsSlice::class,
            'platform.idempotency' => EnforceIdempotency::class,
        ]);

        // No stateful session for the API. Admin session auth arrives in
        // Phase 01 on its own middleware group.
        $middleware->statefulApi(false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Every unhandled throwable becomes the stable envelope. Error
         * responses expose a machine code and a safe message, never a stack
         * trace, SQL, an object key, or a provider payload (phase file, "API
         * contract"). The request_id is the only handle support needs.
         */
        $exceptions->render(
            static fn (Throwable $e, Request $request) => ExceptionRenderer::render($e, $request),
        );
    })
    ->create();
