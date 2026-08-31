<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Responses;

use Illuminate\Http\JsonResponse;
use Modules\Platform\Support\Identifier;

/**
 * Builds the one response shape every endpoint uses (plan.md section 106).
 *
 *   {"data": …, "meta": {…}, "errors": [], "request_id": "…"}
 *
 * Centralized so the shape cannot drift per controller, and so `request_id` is
 * structurally impossible to omit: a client with a request_id can always be
 * helped by support, and a client without one cannot.
 *
 * Error construction lives in ErrorEnvelope, which owns the mapping from
 * machine code to HTTP status, so no controller invents a status for a code.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function ok(
        mixed $data,
        Identifier $requestId,
        array $meta = [],
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse(
            [
                'data' => $data,
                'meta' => (object) $meta,
                'errors' => [],
                'request_id' => $requestId->value,
            ],
            $status,
            array_merge(['X-Request-Id' => $requestId->value], $headers),
            // Unescaped slashes and unicode so Arabic messages travel as
            // Arabic rather than as \uXXXX escapes, which clients then have to
            // undo and which make log inspection needlessly painful.
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function created(
        mixed $data,
        Identifier $requestId,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return self::ok($data, $requestId, $meta, 201, $headers);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function noContent(Identifier $requestId, array $meta = []): JsonResponse
    {
        return self::ok(null, $requestId, $meta, 200);
    }
}
