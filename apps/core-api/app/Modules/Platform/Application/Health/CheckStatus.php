<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Health;

/**
 * Outcome of one readiness check.
 *
 * Degraded exists so an optional dependency can be reported as down without
 * claiming the process is unable to serve. Collapsing Degraded into Fail is
 * exactly how an AI outage becomes a core outage.
 */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Degraded = 'degraded';
    case Fail = 'fail';
}
