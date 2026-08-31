<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Privacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Discards Platform-owned transient copies that carry a subject identifier.
 *
 * Identity calls this service. Platform does not import Identity. Framework
 * jobs/cache/locks are not subject-erasure targets.
 */
final class DiscardSubjectTransientCopies
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @param  list<string>  $authSessionIds
     * @return array{notifications: int, laravel_sessions: int, pending_or_failed_outbox: int}
     */
    public function snapshot(string $userId, array $authSessionIds): array
    {
        return [
            'notifications' => $this->connection->table('notifications')
                ->where('notifiable_id', $userId)
                ->count(),
            'laravel_sessions' => $this->connection->table('sessions')
                ->where('user_id', $userId)
                ->count(),
            'pending_or_failed_outbox' => $this->pendingOrFailedOutboxQuery($userId, $authSessionIds)->count(),
        ];
    }

    /**
     * @param  list<string>  $authSessionIds
     * @return array{notifications: int, laravel_sessions: int, pending_or_failed_outbox: int}
     */
    public function discard(string $userId, array $authSessionIds): array
    {
        $notifications = $this->connection->table('notifications')
            ->where('notifiable_id', $userId)
            ->delete();

        $laravelSessions = $this->connection->table('sessions')
            ->where('user_id', $userId)
            ->delete();

        $outbox = $this->pendingOrFailedOutboxQuery($userId, $authSessionIds)->delete();

        return [
            'notifications' => $notifications,
            'laravel_sessions' => $laravelSessions,
            'pending_or_failed_outbox' => $outbox,
        ];
    }

    /**
     * @param  list<string>  $authSessionIds
     */
    private function pendingOrFailedOutboxQuery(string $userId, array $authSessionIds): Builder
    {
        return $this->connection->table('outbox_events')
            ->whereIn('status', ['PENDING', 'FAILED'])
            ->where(function ($query) use ($userId, $authSessionIds): void {
                $query->where(function ($inner) use ($userId): void {
                    $inner->where('aggregate_type', 'User')->where('aggregate_id', $userId);
                });

                if ($authSessionIds !== []) {
                    $query->orWhere(function ($inner) use ($authSessionIds): void {
                        $inner->where('aggregate_type', 'AuthSession')->whereIn('aggregate_id', $authSessionIds);
                    });
                }
            });
    }
}
