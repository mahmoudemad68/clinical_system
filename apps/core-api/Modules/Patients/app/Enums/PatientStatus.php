<?php

declare(strict_types=1);

namespace Modules\Patients\Enums;

enum PatientStatus: string
{
    case Active = 'active';
    case Disputed = 'disputed';
    case Merged = 'merged';
    case Restricted = 'restricted';
    case Archived = 'archived';

    public function isAuthoritative(): bool
    {
        return $this !== self::Merged;
    }

    public function isClaimEligible(): bool
    {
        return $this === self::Active;
    }
}
