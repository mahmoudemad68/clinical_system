<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ClosedJsonValidator
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Request $request, array $rules): array
    {
        $data = $request->validate($rules);
        $payload = $request->json()?->all();
        if (! is_array($payload) || $payload === []) {
            $payload = $request->request->all();
        }

        $allowed = array_keys($rules);
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $unknown[0] => 'Unexpected property.',
            ]);
        }

        return $data;
    }
}
