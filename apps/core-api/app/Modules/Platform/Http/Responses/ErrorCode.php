<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Responses;

/**
 * The stable machine codes clients branch on.
 *
 * Mirrors the ErrorCode enum in packages/contracts/openapi/openapi.yaml, and a
 * contract test asserts the two lists stay identical. Clients switch on the
 * code; the message is for humans and may be reworded or re-translated at any
 * time without being a breaking change.
 *
 * The status mapping lives here so no controller invents one. Two choices in it
 * are deliberate and worth stating:
 *
 *   - NotFound is returned where disclosing existence would leak. A patient
 *     probing another patient's appointment gets the same 404 whether or not it
 *     exists, so the response is not an existence oracle.
 *   - PermissionDenied carries one generic message for every denial reason.
 *     A message that explains why access was refused explains the shape of the
 *     authorization model to an attacker.
 */
enum ErrorCode: string
{
    case MalformedRequest = 'MALFORMED_REQUEST';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';
    case RequestTooLarge = 'REQUEST_TOO_LARGE';
    case Unauthenticated = 'UNAUTHENTICATED';
    case CsrfMismatch = 'CSRF_MISMATCH';
    case TokenExpired = 'TOKEN_EXPIRED';
    case PermissionDenied = 'PERMISSION_DENIED';
    case NotFound = 'NOT_FOUND';
    case StateConflict = 'STATE_CONFLICT';
    case VersionConflict = 'VERSION_CONFLICT';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotencyInProgress = 'IDEMPOTENCY_IN_PROGRESS';
    case ValidationFailed = 'VALIDATION_FAILED';
    case CursorInvalid = 'CURSOR_INVALID';
    case UnsupportedSchemaVersion = 'UNSUPPORTED_SCHEMA_VERSION';
    case RateLimited = 'RATE_LIMITED';
    case DependencyUnavailable = 'DEPENDENCY_UNAVAILABLE';
    case InternalError = 'INTERNAL_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::MalformedRequest => 400,
            self::UnsupportedMediaType => 415,
            self::RequestTooLarge => 413,
            self::Unauthenticated, self::TokenExpired => 401,
            self::CsrfMismatch => 403,
            self::PermissionDenied => 403,
            self::NotFound => 404,
            self::StateConflict,
            self::VersionConflict,
            self::IdempotencyKeyReused,
            self::IdempotencyInProgress => 409,
            self::ValidationFailed,
            self::CursorInvalid,
            self::UnsupportedSchemaVersion => 422,
            self::RateLimited => 429,
            self::DependencyUnavailable => 503,
            self::InternalError => 500,
        };
    }

    /**
     * Translation key for the safe human message.
     *
     * Messages live in lang/{ar,en}/errors.php. Hard-coded strings are
     * forbidden (plan.md section 148), and a translated message means a patient
     * sees Arabic without the client having to maintain its own error copy.
     */
    public function translationKey(): string
    {
        return 'errors.'.strtolower($this->value);
    }

    /**
     * Should this failure be logged at error level rather than info?
     *
     * A client sending a malformed body is not an incident. A dependency
     * failing is. Getting this wrong in either direction is costly: noisy logs
     * get ignored, and quiet ones hide real failures.
     */
    public function isServerFault(): bool
    {
        return $this->httpStatus() >= 500;
    }
}
