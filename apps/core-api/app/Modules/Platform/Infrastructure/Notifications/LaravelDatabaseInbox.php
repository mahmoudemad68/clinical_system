<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Notifications;

use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\RecordInboxNotification;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Writes {@see DatabaseNotification} rows.
 *
 * Controllers and domain code never touch this table or the Firebase SDK.
 */
final class LaravelDatabaseInbox implements RecordInboxNotification
{
    /** @var list<string> */
    private const DROPPED_KEYS = ['body', 'message', 'title', 'patient', 'diagnosis', 'note'];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IdentityGenerator $identities,
        private readonly Clock $clock,
    ) {}

    public function record(
        string $notifiableType,
        string $notifiableId,
        string $notificationType,
        array $data,
    ): void {
        $payload = ['notification_type' => $notificationType];

        foreach ($data as $key => $value) {
            if (in_array($key, self::DROPPED_KEYS, true) || $key === 'notification_type') {
                continue;
            }

            $payload[$key] = $value;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->connection->table('notifications')->insert([
            'id' => $this->identities->next()->value,
            'type' => InboxDatabaseNotification::class,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'data' => json_encode($payload, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
