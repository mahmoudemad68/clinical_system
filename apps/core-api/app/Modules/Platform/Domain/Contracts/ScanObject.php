<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\StoredObjectRef;

/**
 * Scan a stored object for malware and type. Isolated from storage itself.
 *
 * Phase 00 ships the port. Quarantine/release is Phase 02/07.
 */
interface ScanObject
{
    /**
     * @return array{clean: bool, mime: string, scanner: string}
     */
    public function scan(StoredObjectRef $ref): array;
}
