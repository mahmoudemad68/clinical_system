<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Closed JSON for doctor verification writes. Clients cannot assign applicant
 * identity, case type, reviewer, verification status, or public status.
 */
final class DoctorVerificationRules
{
    /**
     * Empty closed body for opening or resuming the caller's own draft case.
     *
     * @return array<string, list<mixed>>
     */
    public static function open(): array
    {
        return [];
    }
}
