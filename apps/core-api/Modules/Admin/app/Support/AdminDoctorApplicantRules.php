<?php

declare(strict_types=1);

namespace Modules\Admin\Support;

/**
 * Closed Admin-created doctor HTTP input. Server-owned verification, public
 * status, capabilities, crypto, and bootstrap flags are absent so mass
 * assignment cannot set them.
 */
final class AdminDoctorApplicantRules
{
    public const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @return array<string, list<mixed>>
     */
    public static function create(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'national_id' => ['required', 'string', 'max:32'],
            'professional_display_name' => ['required', 'string', 'min:1', 'max:200'],
            'specialty_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'syndicate_number' => ['sometimes', 'nullable', 'string', 'min:1', 'max:64'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'evidence_source' => ['required', 'string', 'in:in_person_originals,certified_copy'],
        ];
    }
}
