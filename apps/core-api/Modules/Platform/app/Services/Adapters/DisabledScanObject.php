<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Support\ScanVerdict;

/**
 * Fail-closed scanner. Production binds ClamdScanObject when a scanner host
 * is configured. An unavailable/disabled scanner is never a clean verdict.
 */
final class DisabledScanObject implements ScanObject
{
    public function scanStream(mixed $stream, int $sizeBytes): ScanVerdict
    {
        if (is_resource($stream)) {
            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
            }
        }

        unset($sizeBytes);

        return ScanVerdict::unavailable('disabled');
    }
}
