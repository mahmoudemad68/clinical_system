<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Closed JSON for doctor verification document uploads.
 */
final class VerificationUploadRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function create(): array
    {
        return [
            'case_id' => ['required', 'uuid'],
            'requirement_code' => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:64'],
            'expected_size_bytes' => ['required', 'integer', 'min:1', 'max:20971520'],
            'declared_media_type' => ['required', 'string', 'in:application/pdf,image/jpeg,image/png'],
            'sha256' => ['sometimes', 'nullable', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function complete(): array
    {
        return [];
    }
}
