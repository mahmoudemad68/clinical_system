<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Health;

use App\Modules\Platform\Application\Health\CheckStatus;
use App\Modules\Platform\Application\Health\DependencyCheck;

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
        }

        return CheckStatus::Pass;
    }
}
