<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the foundation slice payload.
 *
 * The rules mirror the OpenAPI schema and the database CHECK constraints
 * exactly. Three layers say the same thing on purpose: the contract documents
 * it, this rejects it at the edge with a useful message, and the constraint
 * survives a caller that reaches the database another way.
 *
 * The label pattern is a lowercase slug with no spaces, and the server rejects
 * any run of 10 or more digits. Both exist so a national ID (14 digits) or a
 * mobile number (11) cannot ride along in what is nominally a test label.
 */
final class DiagnosticsRoundTripRequest extends FormRequest
{
    /**
     * Authorization is handled by RequireDiagnosticsSlice before this runs.
     *
     * Returning true here does not mean "anyone may call this": it means the
     * decision was already made by a dedicated gate rather than being buried
     * in a form request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[a-z][a-z0-9_-]{0,63}$/', 'not_regex:/[0-9]{10}/'],
            'echo_delay_ms' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * Reject unknown properties outright.
     *
     * The OpenAPI schema sets additionalProperties:false, and mass assignment
     * is a named threat in the Phase 00 threat model. Silently ignoring an
     * unexpected field would let a caller believe it took effect.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.regex' => 'The label must be a lowercase slug starting with a letter, without spaces.',
            'label.not_regex' => 'The label must not contain a long run of digits.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_keys($this->all()), ['label', 'echo_delay_ms']);

        if ($unknown !== []) {
            // Fail loudly rather than dropping the field. A client sending an
            // unrecognised property has a wrong model of the contract, and
            // discovering that at the edge is far cheaper than later.
            abort(422, 'Unknown properties are not accepted.');
        }
    }
}
