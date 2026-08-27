<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\OperationalController;

/*
|--------------------------------------------------------------------------
| Operational probes
|--------------------------------------------------------------------------
|
| Consumed by the orchestrator and the load balancer. Unversioned, not
| enveloped, and not part of the client contract.
|
| These must not be exposed through the public gateway route: /ready
| enumerates dependency names, which is useful to an operator and useful to
| an attacker. The gateway config forwards /api/* only.
|
| No middleware. A probe that depends on the middleware stack cannot report
| on a process whose middleware stack is what is broken.
|
*/

Route::get('/live', [OperationalController::class, 'live'])->name('operational.live');
Route::get('/ready', [OperationalController::class, 'ready'])->name('operational.ready');
Route::get('/metrics', [OperationalController::class, 'metrics'])->name('operational.metrics');
