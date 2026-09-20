<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Idempotency;

use Illuminate\Http\Request;
use Modules\Platform\Contracts\IdempotencyReplayHydrator;

/**
 * Default hydrator. Platform stays business-generic.
 */
final class NullIdempotencyReplayHydrator implements IdempotencyReplayHydrator
{
    public function hydrate(array $pointer, Request $request): ?array
    {
        unset($pointer, $request);

        return null;
    }
}
