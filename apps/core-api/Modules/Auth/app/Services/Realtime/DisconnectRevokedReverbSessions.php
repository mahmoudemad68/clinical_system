<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Realtime;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Throwable;

/**
 * Closes live Reverb sockets for a revoked auth session.
 *
 * The HTTP process cannot see in-memory Reverb connections. The Reverb process
 * drains a Redis list written by SessionRevokedConsumer and terminates the
 * matching private-channel subscribers. HTTP deny remains authoritative.
 */
final class DisconnectRevokedReverbSessions
{
    public const QUEUE_KEY = 'clinic.session.disconnect';

    public function __construct(private readonly Application $app) {}

    public function drainQueue(): int
    {
        $closed = 0;

        while (true) {
            $raw = Redis::connection('realtime')->lpop(self::QUEUE_KEY);
            if (! is_string($raw) || $raw === '') {
                break;
            }

            $closed += $this->disconnectEncoded($raw);
        }

        return $closed;
    }

    public function disconnectEncoded(string $raw): int
    {
        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 0;
        }

        if (! is_array($payload)) {
            return 0;
        }

        $sessionId = (string) ($payload['session_id'] ?? '');
        if ($sessionId === '') {
            return 0;
        }

        return $this->disconnectSession($sessionId);
    }

    /**
     * @return list<string>
     */
    public static function channelNamesForSession(string $sessionId): array
    {
        return [
            'private-auth.session.'.$sessionId,
            'presence-auth.session.'.$sessionId,
        ];
    }

    public function disconnectSession(string $sessionId): int
    {
        if (! $this->app->bound(ChannelManager::class) || ! $this->app->bound(ApplicationProvider::class)) {
            return 0;
        }

        $closed = 0;

        try {
            $applications = $this->app->make(ApplicationProvider::class)->all();
            $manager = $this->app->make(ChannelManager::class);
        } catch (Throwable) {
            return 0;
        }

        if (! is_object($manager) || ! method_exists($manager, 'for')) {
            return 0;
        }

        foreach ($applications as $application) {
            try {
                $channels = $manager->for($application);
            } catch (Throwable) {
                continue;
            }

            foreach (self::channelNamesForSession($sessionId) as $name) {
                if (! is_object($channels) || ! method_exists($channels, 'find')) {
                    continue;
                }

                try {
                    $channel = $channels->find($name);
                } catch (Throwable) {
                    continue;
                }

                if (! is_object($channel) || ! method_exists($channel, 'connections')) {
                    continue;
                }

                foreach ($channel->connections() as $connection) {
                    if (! is_object($connection) || ! method_exists($connection, 'connection')) {
                        continue;
                    }

                    $underlying = $connection->connection();
                    if (! is_object($underlying) || ! method_exists($underlying, 'disconnect')) {
                        continue;
                    }

                    try {
                        $underlying->disconnect();
                        $closed++;
                    } catch (Throwable) {
                        // Continue remaining sockets. HTTP deny remains the gate.
                    }
                }
            }
        }

        return $closed;
    }
}
