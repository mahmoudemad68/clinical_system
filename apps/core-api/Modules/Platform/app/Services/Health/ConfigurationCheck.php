<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Health;

/**
 * Critical configuration is present and non-empty.
 *
 * The phase file requires configuration validation at startup and readiness to
 * stay false while a critical value is invalid, so a misconfigured process
 * never quietly serves traffic on a framework default.
 */
final class ConfigurationCheck implements DependencyCheck
{
    /**
     * @param  list<string>  $requiredKeys
     */
    public function __construct(private readonly array $requiredKeys) {}

    public function name(): string
    {
        return 'configuration';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function run(): CheckStatus
    {
        foreach ($this->requiredKeys as $key) {
            $value = config($key);

            if ($value === null || $value === '') {
                return CheckStatus::Fail;
            }
        }

        $min = (int) config('identity.hmac.min_key_length', 32);
        foreach (['identity.hmac.keys.1', 'identity.encryption.keys.1'] as $key) {
            if (strlen((string) config($key)) < $min) {
                return CheckStatus::Fail;
            }
        }

        if ((string) config('app.env') === 'production') {
            if (! (bool) config('session.secure')) {
                return CheckStatus::Fail;
            }

            if (! str_starts_with((string) config('app.url'), 'https://')) {
                return CheckStatus::Fail;
            }

            if (config('identity.trusted_proxies') === []) {
                return CheckStatus::Fail;
            }

            foreach (['pgsql', 'pgsql_migrator', 'pgsql_worker', 'pgsql_reporter', 'pgsql_audit'] as $connection) {
                $sslMode = (string) config('database.connections.'.$connection.'.sslmode');
                if (! in_array($sslMode, ['require', 'verify-ca', 'verify-full'], true)) {
                    return CheckStatus::Fail;
                }
            }

            if ((bool) config('audit.checkpoint.required', false)) {
                $public = (string) config('audit.checkpoint.public_key', '');
                $publicFile = (string) config('audit.checkpoint.public_key_file', '');
                if ($public === '' && $publicFile === '') {
                    return CheckStatus::Fail;
                }
            }

            if (! $this->productionReverbIsSafe()) {
                return CheckStatus::Fail;
            }
        }

        return CheckStatus::Pass;
    }

    private function productionReverbIsSafe(): bool
    {
        $apps = config('reverb.apps.apps');
        if (! is_array($apps) || $apps === []) {
            return false;
        }

        $app = $apps[0] ?? null;
        if (! is_array($app)) {
            return false;
        }

        foreach (['app_id', 'key', 'secret'] as $credential) {
            $value = $app[$credential] ?? null;
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        if (($app['origins_explicit'] ?? false) !== true) {
            return false;
        }

        $origins = $app['allowed_origins'] ?? null;
        if (! is_array($origins) || $origins === []) {
            return false;
        }

        foreach ($origins as $origin) {
            if (! is_string($origin) || $origin === '') {
                return false;
            }

            $host = strtolower($origin);
            if ($host === '*' || str_contains($host, '*')) {
                return false;
            }

            if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
                || str_ends_with($host, '.localhost')) {
                return false;
            }
        }

        if ((bool) config('reverb.servers.reverb.host_explicit') !== true) {
            return false;
        }

        if (($app['scheme_explicit'] ?? false) !== true) {
            return false;
        }

        $scheme = (string) ($app['options']['scheme'] ?? '');
        $useTls = (bool) ($app['options']['useTLS'] ?? false);
        if ($scheme === 'https') {
            return $useTls;
        }

        return $scheme === 'http' && ! $useTls;
    }
}
