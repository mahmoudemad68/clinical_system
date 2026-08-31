<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider as LaravelTelescopeServiceProvider;
use Modules\Platform\Services\Telemetry\RedactingLogTap;
use Monolog\Handler\NullHandler;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(LaravelTelescopeServiceProvider::class)) {
            $this->loadMigrationsFrom(database_path('telescope'));
            $this->app->register(LaravelTelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        $this->assertLogRedaction();
    }

    private function assertLogRedaction(): void
    {
        $channels = (array) config('logging.channels', []);

        foreach ($channels as $name => $channel) {
            if (! is_array($channel) || in_array($name, ['stack', 'null'], true)) {
                continue;
            }

            if (($channel['driver'] ?? '') === 'stack' || ($channel['handler'] ?? null) === NullHandler::class) {
                continue;
            }

            $tap = $channel['tap'] ?? [];
            if (! is_array($tap)) {
                $tap = [$tap];
            }

            if (! in_array(RedactingLogTap::class, $tap, true)) {
                $tap[] = RedactingLogTap::class;
                $channels[$name]['tap'] = $tap;
            }
        }

        config(['logging.channels' => $channels]);

        foreach ((array) config('logging.channels', []) as $name => $channel) {
            if (! is_array($channel) || in_array($name, ['stack', 'null'], true)) {
                continue;
            }

            if (($channel['driver'] ?? '') === 'stack' || ($channel['handler'] ?? null) === NullHandler::class) {
                continue;
            }

            $tap = $channel['tap'] ?? [];
            if (! is_array($tap) || ! in_array(RedactingLogTap::class, $tap, true)) {
                throw new RuntimeException('Log channel '.$name.' is missing RedactingLogTap.');
            }
        }
    }
}
