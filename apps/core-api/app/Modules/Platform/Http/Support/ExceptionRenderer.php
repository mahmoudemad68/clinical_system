<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Support;

use App\Modules\Platform\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\Exceptions\FeatureUnavailable;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\Exceptions\RateLimited;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Http\Responses\ErrorCode;
use App\Modules\Platform\Http\Responses\ErrorEnvelope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps any throwable onto the stable error envelope.
 *
 * The single rule: nothing internal crosses the boundary. No stack trace, no
 * SQL, no object key, no provider payload, no class name, and no confirmation
 * that a protected resource exists. The client gets a machine code, a safe
 * localized message, and a request_id; the detail lives in the trace.
 *
 * Note what is deliberately collapsed. An authorization failure and a missing
 * record both become 404 where hiding existence is safer, and every denial
 * carries one identical message. A response that explains *why* access was
 * refused is a map of the authorization model.
 */
final class ExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?Response
    {
        // Non-API paths keep the framework's own handling.
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        $requestId = self::requestId($request);

        return match (true) {
            $e instanceof ValidationException => ErrorEnvelope::validation($e->errors(), $requestId),

            $e instanceof AuthenticationException,
            $e instanceof AuthenticationFailed,
            $e instanceof TokenMismatchException => ErrorEnvelope::of(ErrorCode::Unauthenticated, $requestId),

            // Authorization denial and "not found" answer identically, so the
            // response cannot be used to probe for the existence of a record
            // the caller may not see.
            $e instanceof AuthorizationException,
            $e instanceof AuthorizationDenied,
            $e instanceof FeatureUnavailable,
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ErrorEnvelope::of(ErrorCode::NotFound, $requestId),

            $e instanceof RateLimited => ErrorEnvelope::of(
                ErrorCode::RateLimited,
                $requestId,
                headers: ['Retry-After' => (string) $e->retryAfterSeconds],
            ),

            $e instanceof TooManyRequestsHttpException => ErrorEnvelope::of(
                ErrorCode::RateLimited,
                $requestId,
                headers: array_filter(['Retry-After' => $e->getHeaders()['Retry-After'] ?? null]),
            ),

            // A value object rejecting input is a client-side validation
            // failure. Its message is written to be safe to surface: it names
            // the expectation and never echoes the offending value.
            $e instanceof InvalidValueObject => ErrorEnvelope::of(
                ErrorCode::ValidationFailed,
                $requestId,
                message: $e->getMessage(),
            ),

            $e instanceof HttpExceptionInterface => self::fromStatus($e->getStatusCode(), $requestId),

            default => ErrorEnvelope::of(ErrorCode::InternalError, $requestId),
        };
    }

    private static function fromStatus(int $status, Identifier $requestId): Response
    {
        $code = match ($status) {
            400 => ErrorCode::MalformedRequest,
            401 => ErrorCode::Unauthenticated,
            403, 404 => ErrorCode::NotFound,
            405 => ErrorCode::MalformedRequest,
            409 => ErrorCode::StateConflict,
            413 => ErrorCode::RequestTooLarge,
            415 => ErrorCode::UnsupportedMediaType,
            419 => ErrorCode::Unauthenticated,
            422 => ErrorCode::ValidationFailed,
            429 => ErrorCode::RateLimited,
            503 => ErrorCode::DependencyUnavailable,
            default => ErrorCode::InternalError,
        };

        return ErrorEnvelope::of($code, $requestId);
    }

    private static function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
