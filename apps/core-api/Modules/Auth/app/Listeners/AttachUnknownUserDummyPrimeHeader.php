<?php

declare(strict_types=1);

namespace Modules\Auth\Listeners;

use Laravel\Octane\Events\RequestHandled;
use Modules\Auth\Contracts\PasswordHasher;

/**
 * Local G-01-18 / DEF-C17-ARGON-001 evidence only. Emits whether this
 * worker already holds the unknown-user dummy before login is attempted.
 */
final class AttachUnknownUserDummyPrimeHeader
{
    public function handle(RequestHandled $event): void
    {
        if (! config('octane.worker_probe')) {
            return;
        }

        $primed = $event->sandbox->make(PasswordHasher::class)->unknownUserDummyIsPrimed();
        $event->response->headers->set('X-Octane-Argon-Dummy-Primed', $primed ? '1' : '0');
    }
}
