<?php

declare(strict_types=1);

namespace Modules\Admin\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Closed Admin verification HTTP input. Unknown fields deny.
 */
final class AdminVerificationRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function claim(): array
    {
        return [
            'expected_case_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function decide(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approved,rejected,changes_requested'],
            'reason_code' => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:64'],
            'expected_case_version' => ['required', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.self::notesMax()],
        ];
    }

    /**
     * Empty closed body for document-access grants. Unknown JSON fields deny.
     *
     * @return array<string, list<mixed>>
     */
    public static function documentAccess(): array
    {
        return [];
    }

    /**
     * @return array{
     *     assignment: string,
     *     case_type: string,
     *     status: string,
     *     cursor: string|null,
     *     limit: int
     * }
     */
    public static function queueQuery(Request $request, VerificationPolicy $policy): array
    {
        $payload = $request->query();
        if (! is_array($payload)) {
            $payload = [];
        }

        $allowed = ['assignment', 'case_type', 'status', 'cursor', 'limit'];
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $unknown[0] => 'Unexpected property.',
            ]);
        }

        $validated = $request->validate([
            'assignment' => ['sometimes', 'string', 'in:unassigned,mine,all'],
            'case_type' => ['sometimes', 'string', 'in:doctor_verification'],
            'status' => ['sometimes', 'string', 'in:pending_review'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.$policy->queueMaxLimit()],
        ]);

        return [
            'assignment' => (string) ($validated['assignment'] ?? 'unassigned'),
            'case_type' => (string) ($validated['case_type'] ?? 'doctor_verification'),
            'status' => (string) ($validated['status'] ?? 'pending_review'),
            'cursor' => isset($validated['cursor']) && is_string($validated['cursor']) && $validated['cursor'] !== ''
                ? $validated['cursor']
                : null,
            'limit' => isset($validated['limit']) ? (int) $validated['limit'] : $policy->queueDefaultLimit(),
        ];
    }

    private static function notesMax(): int
    {
        return (int) config('verification_module.notes_max_length', 2000);
    }
}
