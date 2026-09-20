<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Support\ScanVerdict;

/**
 * Test double. Treats the EICAR string as infected; everything else is clean.
 * Not a production adapter.
 */
final class FixtureScanObject implements ScanObject
{
    public const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function __construct(
        private readonly ?ScanVerdict $forced = null,
    ) {}

    public function scanStream(mixed $stream, int $sizeBytes): ScanVerdict
    {
        $buffer = '';
        if (is_resource($stream)) {
            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer .= $chunk;
            }
        }

        unset($sizeBytes);

        if ($this->forced instanceof ScanVerdict) {
            return $this->forced;
        }

        if (str_contains($buffer, self::EICAR)) {
            return ScanVerdict::infected('fixture', 'test');
        }

        return ScanVerdict::clean('fixture', 'test');
    }
}
