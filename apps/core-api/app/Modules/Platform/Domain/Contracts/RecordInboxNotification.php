<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Persist a user-visible notification in the Laravel notifications table.
 *
 * The database row is the inbox source of truth. Push delivery is a separate
 * port ({@see SendPush}) and must not replace this write.
 *
 * @phpstan-type InboxData array<string, bool|float|int|string>
 */
interface RecordInboxNotification
{
    /**
     * @param  InboxData  $data  opaque resource references only
     */
    public function record(
        string $notifiableType,
        string $notifiableId,
        string $notificationType,
        array $data,
    ): void;
}
