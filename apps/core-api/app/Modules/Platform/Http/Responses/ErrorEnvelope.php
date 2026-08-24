<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Responses;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use Illuminate\Http\JsonResponse;

/**
 * Builds failure responses.
 *
 * The one rule this class exists to enforce: nothing sensitive crosses the
 * boundary. No stack trace, no SQL, no object key, no provider payload, no
 * indication that a protected resource exists. The client receives a stable
 * code, a safe localized message, and a request_id that lets support find the
 * full detail in the trace.
 */
final class ErrorEnvelope
{
    /**
     * A single error.
     *
     * @param array<string, mixed> $meta bounded, non-sensitive detail such as an allowed range
     */
    public static function of(
        ErrorCode $code,
        Identifier $requestId,
        ?string $message = null,
        ?string $field = null,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $error = ['code' => $code->value, 'message' => $message ?? self::translate($code)];

        if ($field !== null) {
            $error['field'] = $field;
        }

        if ($meta !== []) {
            $error['meta'] = $meta;
        }

        return self::respond([$error], $code->httpStatus(), $requestId, $headers);
    }

    /**
     * Field-level validation failures, one entry per field.
     *
     * @param array<string, list<string>> $failures field path => messages
     */
    public static function validation(
        array $failures,
        Identifier $requestId,
        ErrorCode $code = ErrorCode::ValidationFailed,
    ): JsonResponse {
        $errors = [];

        foreach ($failures as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'code' => $code->value,
                    'message' => $message,
                    'field' => (string) $field,
                ];
            }
        }

        if ($errors === []) {
            $errors[] = ['code' => $code->value, 'message' => self::translate($code)];
        }

        return self::respond($errors, $code->httpStatus(), $requestId);
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private static function respond(
        array $errors,
        int $status,
        Identifier $requestId,
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse(
            [
                'data' => null,
                'meta' => (object) ['locale' => app()->getLocale()],
                'errors' => $errors,
                'request_id' => $requestId->value,
            ],
            $status,
            array_merge(['X-Request-Id' => $requestId->value], $headers),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function translate(ErrorCode $code): string
    {
        $key = $code->translationKey();
        $translated = trans($key);

        // trans() returns the key itself when no translation exists. Shipping
        // "errors.validation_failed" to a patient is worse than a generic
        // sentence, so fall back rather than leak an internal key.
        return is_string($translated) && $translated !== $key
            ? $translated
            : 'The request could not be completed.';
    }
}
