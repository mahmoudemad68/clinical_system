<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use DateTimeImmutable;
use Modules\Auth\Events\SessionRevoked;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Support\Identifier;

/**
 * Records one SessionRevoked outbox event per normalized auth_sessions row.
 */
final class RecordSessionRevokedEvents
{
    /**
     * @param  list<string>  $sessionIds
     */
    public function onto(
        TransactionContext $tx,
        Identifier $userId,
        array $sessionIds,
        string $reason,
        DateTimeImmutable $now,
    ): void {
        $seen = [];

        foreach ($sessionIds as $raw) {
            if ($raw === '' || isset($seen[$raw])) {
                continue;
            }

            $seen[$raw] = true;
            $tx->recordEvent(new SessionRevoked($userId, Identifier::fromTrusted($raw), $reason, $now));
        }
    }
}
