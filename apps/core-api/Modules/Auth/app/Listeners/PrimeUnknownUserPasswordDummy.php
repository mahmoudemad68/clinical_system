<?php

declare(strict_types=1);

namespace Modules\Auth\Listeners;

use Laravel\Octane\Events\WorkerStarting;
use Modules\Auth\Contracts\PasswordHasher;
use RuntimeException;

/**
 * Canonical Octane worker-start hook. FrankenPHP runs Worker::boot() on
 * initial start, octane:reload, and max-requests recycle. Each boot warms
 * services (construction only) then dispatches WorkerStarting before
 * frankenphp_handle_request().
 */
final class PrimeUnknownUserPasswordDummy
{
    public function handle(WorkerStarting $event): void
    {
        $hasher = $event->app->make(PasswordHasher::class);
        $hasher->primeUnknownUserDummy();

        if (! $hasher->unknownUserDummyIsPrimed()) {
            throw new RuntimeException('Octane worker started without an unknown-user Argon2id dummy.');
        }
    }
}
