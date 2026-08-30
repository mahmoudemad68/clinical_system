<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:prune-expired')->hourly();
Schedule::command('audit:verify-chain')->everyFifteenMinutes();
Schedule::command('audit:checkpoint-chain')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('identity:apply-due-recoveries')->everyFifteenMinutes();
