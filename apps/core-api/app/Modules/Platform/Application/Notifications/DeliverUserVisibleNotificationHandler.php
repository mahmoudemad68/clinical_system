<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Notifications;

use App\Modules\Platform\Domain\Contracts\RecordInboxNotification;
use App\Modules\Platform\Domain\Contracts\SendPush;
use Throwable;

/**
 * Inbox first, push second. A disabled or failing push adapter cannot roll
 * back a stored notification: the database row is the source of truth.
 */
final class DeliverUserVisibleNotificationHandler
{
    public function __construct(
        private readonly RecordInboxNotification $inbox,
        private readonly SendPush $push,
    ) {}

    public function handle(DeliverUserVisibleNotification $command): void
    {
        $this->inbox->record(
            $command->notifiableType,
            $command->notifiableId,
            $command->notificationType,
            $command->data,
        );

        if ($command->deviceTokenFingerprint === '') {
            return;
        }

        try {
            $this->push->send(
                $command->deviceTokenFingerprint,
                $command->notificationType,
                $command->data,
            );
        } catch (Throwable) {
            // Delivery is best-effort after the inbox write.
        }
    }
}
