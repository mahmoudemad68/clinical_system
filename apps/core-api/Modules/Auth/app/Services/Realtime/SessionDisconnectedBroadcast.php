<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Hint for subscribed clients to drop the socket. HTTP deny remains authoritative.
 */
final class SessionDisconnectedBroadcast implements ShouldBroadcastNow
{
    public function __construct(public readonly string $sessionId, public readonly string $reasonCode) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('auth.session.'.$this->sessionId)];
    }

    public function broadcastAs(): string
    {
        return 'session.revoked';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'reason_code' => $this->reasonCode,
        ];
    }
}
