<?php

declare(strict_types=1);

/**
 * Safe, client-facing error messages, keyed by the stable machine code.
 *
 * Rules these messages follow:
 *   - never name a table, column, host, provider, or internal identifier;
 *   - never confirm or deny that a protected resource exists;
 *   - PERMISSION_DENIED is identical for every denial reason, so the response
 *     cannot be used to map the authorization model;
 *   - never include a value the client sent, which is how sensitive input ends
 *     up echoed into logs and screenshots.
 */
return [
    'malformed_request' => 'The request could not be understood.',
    'unsupported_media_type' => 'The request content type is not supported.',
    'request_too_large' => 'The request is too large.',
    'unauthenticated' => 'Authentication is required.',
    'csrf_mismatch' => 'The request could not be verified. Reload the page and try again.',
    'token_expired' => 'Your session has expired. Please sign in again.',
    'permission_denied' => 'You do not have access to this resource.',
    'not_found' => 'The requested resource was not found.',
    'state_conflict' => 'This action is not allowed in the current state.',
    'version_conflict' => 'This record changed since you loaded it. Please refresh and try again.',
    'idempotency_key_reused' => 'This request key was already used for a different request.',
    'idempotency_in_progress' => 'An identical request is still being processed.',
    'validation_failed' => 'Some of the submitted values are not valid.',
    'cursor_invalid' => 'The pagination cursor is no longer valid. Please start again.',
    'unsupported_schema_version' => 'This client version is no longer supported.',
    'rate_limited' => 'Too many requests. Please wait and try again.',
    'dependency_unavailable' => 'A required service is temporarily unavailable.',
    'internal_error' => 'Something went wrong on our side.',
];
