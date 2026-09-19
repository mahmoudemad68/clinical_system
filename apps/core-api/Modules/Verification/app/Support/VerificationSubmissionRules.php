<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Closed JSON for doctor verification submission. Clients cannot assign
 * reviewer identity, document availability, or case status.
 */
final class VerificationSubmissionRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function submit(): array
    {
        return [
            'case_version' => ['required', 'integer', 'min:1'],
            'profile_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
