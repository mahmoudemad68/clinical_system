<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Outbox;

use DateTimeImmutable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Redis;
use Modules\Auth\Services\Realtime\SessionDisconnectedBroadcast;
use Modules\Platform\Services\Outbox\OutboxConsumer;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Throwable;

/**
 * Fan-out for realtime disconnect. Reverb unsubscription is eventual; HTTP
 * already denies on the authoritative revoked timestamp. Measured latency is
 * consumer lag, not a proven socket-close SLO (G-01-16 OPEN).
 */
final class SessionRevokedConsumer implements OutboxConsumer
{
    public function __construct(private readonly PlatformMetrics $metrics) {}

    public function handles(): string
    {
        return 'auth.session_revoked';
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function consume(string $eventId, array $payload): void
    {
        $this->metrics->increment('clinic_auth_attempts_total', [
            'result' => 'revoked',
            'method' => 'session',
            'actor_class' => 'unknown',
        ]);

        $latency = 0.0;
        if (isset($payload['revoked_at']) && is_string($payload['revoked_at'])) {
            try {
                $revokedAt = new DateTimeImmutable($payload['revoked_at']);
                $latency = (float) max(0, time() - $revokedAt->getTimestamp());
            } catch (Throwable) {
                $latency = 0.0;
            }
        }

        $this->metrics->set('clinic_session_revocation_latency_seconds', $latency, [
            'client_class' => 'unknown',
        ]);

        try {
            $sessionId = (string) ($payload['session_id'] ?? '');
            $reason = (string) ($payload['reason_code'] ?? '');
            Redis::connection('realtime')->publish('clinic.session.disconnect', json_encode([
                'session_id' => $sessionId,
                'user_id' => $payload['user_id'] ?? '',
                'reason_code' => $reason,
            ], JSON_THROW_ON_ERROR));
            if ($sessionId !== '') {
                Broadcast::event(new SessionDisconnectedBroadcast($sessionId, $reason));
            }
        } catch (Throwable) {
            // HTTP deny remains authoritative. Publish failure is recorded as lag, not success.
        }
    }
}
