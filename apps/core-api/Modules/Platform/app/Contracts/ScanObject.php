<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Modules\Platform\Support\ScanVerdict;

/**
 * Scan a bounded, server-resolved byte stream for malware.
 *
 * Implementations must not accept URLs, signed URLs, or caller-supplied
 * filesystem paths. The caller opens a trusted stream from StoreObject.
 */
interface ScanObject
{
    /**
     * @param  resource  $stream
     */
    public function scanStream(mixed $stream, int $sizeBytes): ScanVerdict;
}
