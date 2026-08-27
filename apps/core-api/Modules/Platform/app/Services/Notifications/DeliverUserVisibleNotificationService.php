<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Notifications;

use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\SendPush;
use Throwable;

/**
 * Inbox first, push second. A disabled or failing push adapter cannot roll
 * back a stored notification: the database row is the source of truth.
 */
final class DeliverUserVisibleNotificationService
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
