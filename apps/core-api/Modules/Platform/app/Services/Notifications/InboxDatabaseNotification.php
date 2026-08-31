<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Platform\Contracts\SendPush;

/**
 * Marker type stored in {@see notifications.type}.
 *
 * Delivery uses {@see SendPush};
 * this class exists so inbox rows use Laravel's database-notification shape.
 */
final class InboxDatabaseNotification extends Notification
{
    /**
     * @param  array<string, bool|float|int|string>  $data
     */
    public function __construct(
        private readonly string $notificationType,
        private readonly array $data,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    public function toArray(object $notifiable): array
    {
        return ['notification_type' => $this->notificationType] + $this->data;
    }
}
