<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Notifications;

/**
 * Persist an inbox row, then attempt push delivery.
 *
 * @phpstan-type InboxData array<string, bool|float|int|string>
 */
final readonly class DeliverUserVisibleNotification
{
    /**
     * @param  InboxData  $data
     */
    public function __construct(
        public string $notifiableType,
        public string $notifiableId,
        public string $notificationType,
        public array $data,
        public string $deviceTokenFingerprint = '',
    ) {}
}
