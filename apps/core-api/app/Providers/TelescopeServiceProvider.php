<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Local-only Telescope. Never a production operations console.
 */
final class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        Telescope::filter(function (IncomingEntry $entry): bool {
            if (! $this->app->environment('local')) {
                return false;
            }

            $uri = $entry->content['uri'] ?? '';

            return ! (is_string($uri) && str_contains($uri, '/api/v1/auth'));
        });
    }

    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'code',
            'totp_code',
            'recovery_code',
            'refresh_token',
            'access_token',
            'national_id',
            'phone',
        ]);
        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'authorization',
        ]);
        Telescope::hideResponseParameters([
            'access_token',
            'refresh_token',
            'code',
            'totp_code',
            'recovery_code',
            'password',
            'national_id',
            'phone',
            'provisioning_uri',
            'recovery_codes',
        ]);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', static fn (): bool => false);
    }
}
