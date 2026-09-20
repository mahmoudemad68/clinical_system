<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Typed malware-scanner verdict. Ambiguous protocol results are not clean.
 */
enum ScanOutcome: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
}
