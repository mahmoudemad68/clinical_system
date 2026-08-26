<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\PersonaStatusController;
use Illuminate\Support\Facades\Route;

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
