<?php

declare(strict_types=1);

namespace Modules\Auth\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Modules\Auth\Services\Realtime\DisconnectRevokedReverbSessions;
use React\EventLoop\Loop;
use Throwable;

/**
 * In the Reverb process only: poll the session-disconnect list on the React
 * event loop and close matching WebSocket connections.
 */
final class ReverbSessionDisconnectListener
{
    public function subscribe(CommandStarting $event): void
    {
        if ($event->command !== 'reverb:start') {
            return;
        }

        Loop::get()->addPeriodicTimer(0.02, static function (): void {
            try {
                app(DisconnectRevokedReverbSessions::class)->drainQueue();
            } catch (Throwable) {
                // HTTP deny remains authoritative if drain fails.
            }
        });
    }
}
