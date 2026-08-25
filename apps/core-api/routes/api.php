<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\DiagnosticsController;
use App\Modules\Platform\Http\Controllers\PlatformHealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API — /api/v1
|--------------------------------------------------------------------------
|
| Every externally reachable route lives here and speaks the response
| envelope. Operational probes (/live, /ready) are deliberately NOT here:
| they are unversioned, unenveloped, and registered in routes/operational.php
| so they can be excluded from the public gateway route.
|
| Phase 00 exposes no clinical, identity, appointment, pharmacy, or AI
| capability. Routes below are health, version, and the flag-gated
| foundation slice.
|
*/

Route::prefix('v1')->group(function (): void {

    // Unauthenticated. Coarse status and build metadata only; never hostnames,
    // dependency versions, or error detail.
    Route::get('/health', [PlatformHealthController::class, 'health'])
        ->name('api.v1.health');

    Route::get('/meta/version', [PlatformHealthController::class, 'version'])
        ->name('api.v1.meta.version');

    /*
     * Foundation slice. Three independent gates stand in front of it:
     *   1. the feature flag (fails closed),
     *   2. the environment allow-list (local/development/testing only),
     *   3. the synthetic device token.
     *
     * When the flag is off the route answers 404 rather than 403, so its
     * existence is not disclosed.
     */
    Route::middleware(['platform.diagnostics', 'platform.idempotency'])
        ->post('/diagnostics/round-trip', [DiagnosticsController::class, 'roundTrip'])
        ->name('api.v1.diagnostics.round-trip');
});
