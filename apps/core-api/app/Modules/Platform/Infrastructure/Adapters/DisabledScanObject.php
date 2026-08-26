<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Adapters;

use App\Modules\Platform\Domain\Contracts\ScanObject;
use App\Modules\Platform\Domain\Exceptions\ProviderNotEnabled;
use App\Modules\Platform\Domain\ValueObjects\StoredObjectRef;

/** Fail-closed scanner. Phase 02/07 supplies the sandboxed scan port. */
final class DisabledScanObject implements ScanObject
{
    public function scan(StoredObjectRef $ref): array
    {
        throw new ProviderNotEnabled('ScanObject is not enabled in Phase 00.');
    }
}
