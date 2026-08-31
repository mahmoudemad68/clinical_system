<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Health;

use Modules\Platform\Support\OriginHost;

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

        $minHmac = (int) config('identity.hmac.min_key_length', 32);
        $minEnc = (int) config('identity.encryption.min_key_length', 32);
        if (! $this->configuredIdentityKeysAreValid($minHmac, $minEnc)) {
            return CheckStatus::Fail;
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

            if (! $this->productionCorsIsSafe()) {
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

            $raw = strtolower(trim($origin));
            if ($raw === '*' || str_contains($raw, '*')) {
                return false;
            }

            $host = OriginHost::fromConfiguredValue($origin);
            if ($host === null || OriginHost::isDeniedInProduction($host)) {
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

    private function configuredIdentityKeysAreValid(int $minHmac, int $minEnc): bool
    {
        $hmacCurrent = (int) config('identity.hmac.current_version', 1);
        $encCurrent = (int) config('identity.encryption.current_version', 1);

        if (! $this->keyMaterialMeetsFloor(config('identity.hmac.keys.'.$hmacCurrent), $minHmac)) {
            return false;
        }

        if (! $this->keyMaterialMeetsFloor(config('identity.encryption.keys.'.$encCurrent), $minEnc)) {
            return false;
        }

        foreach ((array) config('identity.hmac.keys', []) as $material) {
            if (! is_string($material) || $material === '') {
                continue;
            }

            if (strlen($material) < $minHmac) {
                return false;
            }
        }

        foreach ((array) config('identity.encryption.keys', []) as $material) {
            if (! is_string($material) || $material === '') {
                continue;
            }

            if (strlen($material) < $minEnc) {
                return false;
            }
        }

        return true;
    }

    private function keyMaterialMeetsFloor(mixed $material, int $min): bool
    {
        return is_string($material) && $material !== '' && strlen($material) >= $min;
    }

    private function productionCorsIsSafe(): bool
    {
        $origins = config('cors.allowed_origins');
        $patterns = config('cors.allowed_origins_patterns');

        if (! is_array($origins) || $origins === []) {
            return false;
        }

        if (! is_array($patterns) || $patterns !== []) {
            return false;
        }

        foreach ($origins as $origin) {
            if (! is_string($origin) || ! $this->productionOriginIsExactHttps($origin)) {
                return false;
            }
        }

        return true;
    }

    private function productionOriginIsExactHttps(string $origin): bool
    {
        $origin = trim($origin);
        if ($origin === '' || $origin === '*' || str_contains($origin, '*')) {
            return false;
        }

        $parts = parse_url($origin);
        if (! is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || OriginHost::isDeniedInProduction($host)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            return false;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $canonical = 'https://'.$host.$port;
        if ($path === '/') {
            $canonical .= '/';
        }

        return strtolower($origin) === $canonical;
    }
}
