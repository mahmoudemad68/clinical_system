<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AdminSessionController;
use Modules\Platform\Http\Controllers\PersonaStatusController;

$personas = [
    'admin' => '/',
    'patient' => '/patient',
    'doctor' => '/doctor',
    'pharmacy' => '/pharmacy',
];

foreach ($personas as $persona => $path) {
    Route::get($path, PersonaStatusController::class)
        ->defaults('persona', $persona)
        ->name('status.'.$persona);
}

Route::get('/login', [AdminSessionController::class, 'create'])->name('admin.login');
Route::post('/login', [AdminSessionController::class, 'store'])->name('admin.login.store');
Route::get('/mfa', [AdminSessionController::class, 'mfa'])->name('admin.mfa');
Route::post('/mfa', [AdminSessionController::class, 'verifyMfa'])->name('admin.mfa.store');
Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('admin.logout');
