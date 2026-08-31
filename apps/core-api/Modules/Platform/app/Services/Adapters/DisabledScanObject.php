<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Platform\Support\StoredObjectRef;

/** Fail-closed scanner. Phase 02/07 supplies the sandboxed scan port. */
final class DisabledScanObject implements ScanObject
{
    public function scan(StoredObjectRef $ref): array
    {
        throw new ProviderNotEnabled('ScanObject is not enabled in Phase 00.');
    }
}
