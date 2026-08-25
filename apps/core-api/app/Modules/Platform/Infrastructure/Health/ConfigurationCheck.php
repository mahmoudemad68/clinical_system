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

        return CheckStatus::Pass;
    }
}
