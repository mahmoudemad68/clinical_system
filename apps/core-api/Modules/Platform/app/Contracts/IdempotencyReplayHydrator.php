<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Illuminate\Http\Request;

/**
 * Optional reconstruction of a compact idempotency pointer.
 *
 * Platform stores a bounded reference, never signed URLs or response bodies.
 * Owning modules may rehydrate a usable continuation for the same logical
 * intent. Returning null leaves the middleware's generic expansion in place.
 */
interface IdempotencyReplayHydrator
{
    /**
     * @param  array<string, mixed>  $pointer
     * @return array<string, mixed>|null
     */
    public function hydrate(array $pointer, Request $request): ?array;
}
