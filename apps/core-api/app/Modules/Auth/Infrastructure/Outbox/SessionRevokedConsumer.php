<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Outbox;

use App\Modules\Platform\Application\Outbox\OutboxConsumer;
use App\Modules\Platform\Infrastructure\Telemetry\PlatformMetrics;
use DateTimeImmutable;
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
    }
}
