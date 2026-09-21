<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Closed JSON for pharmacy verification writes. Clients cannot assign
 * applicant identity, organization identity, case type, reviewer, or status.
 */
final class PharmacyVerificationRules
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

    /**
     * @return array<string, list<mixed>>
     */
    public static function submit(): array
    {
        return [
            'case_version' => ['required', 'integer', 'min:1'],
            'organization_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
