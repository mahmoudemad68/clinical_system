<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\SendPush;
use Modules\Platform\Exceptions\ProviderNotEnabled;

/** Fail-closed push adapter. Phase 09 supplies the real FCM port. */
final class DisabledSendPush implements SendPush
{
    public function send(string $deviceTokenFingerprint, string $notificationType, array $data): void
    {
        throw new ProviderNotEnabled('SendPush is not enabled in Phase 00.');
    }
}
