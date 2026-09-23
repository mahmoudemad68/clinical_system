<?php

declare(strict_types=1);

namespace App\Console;

use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Support\ScanVerdict;

/**
 * Opt-in clean scanner for local/testing browser fixtures. Not a production adapter.
 */
final class E2eCleanScanObject implements ScanObject
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

        return ScanVerdict::clean('e2e-fixture', 'test');
    }
}
