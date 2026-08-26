<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\AuthController;
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
*/

Route::prefix('v1')->group(function (): void {

    Route::get('/health', [PlatformHealthController::class, 'health'])
        ->name('api.v1.health');

    Route::get('/meta/version', [PlatformHealthController::class, 'version'])
        ->name('api.v1.meta.version');

    Route::middleware(['platform.diagnostics', 'platform.idempotency'])
        ->post('/diagnostics/round-trip', [DiagnosticsController::class, 'roundTrip'])
        ->name('api.v1.diagnostics.round-trip');

    Route::middleware('identity.session')
        ->get('/auth/csrf', [AuthController::class, 'csrf'])
        ->name('api.v1.auth.csrf');

    Route::middleware('platform.idempotency')
        ->post('/auth/registrations', [AuthController::class, 'register'])
        ->name('api.v1.auth.registrations');

    Route::middleware('platform.idempotency')
        ->post('/auth/otp-requests', [AuthController::class, 'requestOtp'])
        ->name('api.v1.auth.otp-requests');

    Route::middleware(['platform.idempotency', 'identity.session'])
        ->post('/auth/otp-verifications', [AuthController::class, 'verifyOtp'])
        ->name('api.v1.auth.otp-verifications');

    Route::middleware('identity.session')
        ->post('/auth/login', [AuthController::class, 'login'])
        ->name('api.v1.auth.login');

    Route::middleware('identity.session')
        ->post('/auth/mfa/challenges/{id}/verify', [AuthController::class, 'verifyMfa'])
        ->name('api.v1.auth.mfa.verify');

    Route::middleware('platform.idempotency')
        ->post('/auth/token/refresh', [AuthController::class, 'refresh'])
        ->name('api.v1.auth.token.refresh');

    Route::post('/auth/recovery/start', [AuthController::class, 'recoveryStart'])
        ->name('api.v1.auth.recovery.start');

    Route::middleware('platform.idempotency')
        ->post('/auth/recovery/complete', [AuthController::class, 'recoveryComplete'])
        ->name('api.v1.auth.recovery.complete');

    Route::middleware(['identity.session', 'auth.actor', 'auth.pending'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->name('api.v1.auth.logout');

        Route::get('/auth/sessions', [AuthController::class, 'sessions'])
            ->name('api.v1.auth.sessions.index');

        Route::delete('/auth/sessions/{sessionId}', [AuthController::class, 'destroySession'])
            ->name('api.v1.auth.sessions.destroy');

        Route::middleware('platform.idempotency')
            ->post('/auth/sessions/revoke-all', [AuthController::class, 'revokeAll'])
            ->name('api.v1.auth.sessions.revoke-all');

        Route::post('/auth/password/change', [AuthController::class, 'changePassword'])
            ->name('api.v1.auth.password.change');

        Route::post('/auth/mfa/totp/enroll', [AuthController::class, 'enrollTotp'])
            ->name('api.v1.auth.mfa.totp.enroll');

        Route::post('/auth/mfa/totp/confirm', [AuthController::class, 'confirmTotp'])
            ->name('api.v1.auth.mfa.totp.confirm');

        Route::post('/auth/mfa/recovery-codes/rotate', [AuthController::class, 'rotateRecoveryCodes'])
            ->name('api.v1.auth.mfa.recovery-codes.rotate');

        Route::post('/auth/mfa/totp/disable', [AuthController::class, 'disableTotp'])
            ->name('api.v1.auth.mfa.totp.disable');

        Route::post('/auth/recovery/requests/{id}/apply', [AuthController::class, 'applyRecovery'])
            ->name('api.v1.auth.recovery.apply');

        Route::get('/me', [AuthController::class, 'me'])
            ->name('api.v1.me.show');

        Route::get('/me/capabilities', [AuthController::class, 'capabilities'])
            ->name('api.v1.me.capabilities');
    });
});
