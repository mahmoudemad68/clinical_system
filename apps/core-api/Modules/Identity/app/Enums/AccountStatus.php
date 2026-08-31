<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

enum AccountStatus: string
{
    case PendingPhone = 'pending_phone';
    case Active = 'active';
    case Suspended = 'suspended';
    case Locked = 'locked';
    case Closed = 'closed';

    public function canReceiveDeviceSession(): bool
    {
        return $this === self::PendingPhone || $this === self::Active;
    }

    public function canAccessBusinessEndpoints(): bool
    {
        return $this === self::Active;
    }
}
