<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:prune-expired')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('access:prune-expired')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('platform:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('queue:prune-failed --hours='.(int) config('platform.queue.failed_job_retention_hours', 168))
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('audit:verify-chain')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->evenInMaintenanceMode()
    ->onFailure(static function (): void {
        Log::critical('scheduled audit:verify-chain failed');
    });
Schedule::command('audit:checkpoint-chain')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('identity:apply-due-recoveries')->everyFifteenMinutes();
