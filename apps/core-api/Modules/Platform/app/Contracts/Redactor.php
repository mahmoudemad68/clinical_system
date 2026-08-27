<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * Centralized redaction applied before anything leaves the process.
 *
 * Phase 00 §5.3 requires a logging redaction processor tested with canary
 * national IDs, phones, tokens, passwords, clinical text, and object keys.
 * Invariant 18 states logs and traces contain identifiers and safe metadata,
 * never raw medical content, credentials, national IDs, prescription text, lab
 * contents, or unrestricted prompts and responses.
 *
 * Two rules make this workable rather than decorative:
 *
 *   1. It runs at the export boundary, not at each call site. A redactor that
 *      depends on every developer remembering to call it has already failed.
 *   2. It is deny-oriented on keys and pattern-oriented on values. Key matching
 *      catches the structured cases; pattern matching catches a national ID
 *      pasted into a free-text field, which key matching never will.
 */
interface Redactor
{
    /**
     * Redact a structured payload, preserving shape.
     *
     * Keys are preserved so a log line stays searchable and diagnosable; only
     * values are replaced. Nested arrays and objects are walked to full depth,
     * with a bound so a hostile or cyclic structure cannot exhaust the process.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redactArray(array $payload): array;

    /**
     * Redact free text such as an exception message or a span attribute.
     */
    public function redactText(string $text): string;

    /**
     * Is this key one whose value must never be emitted?
     */
    public function isSensitiveKey(string $key): bool;

    /**
     * Does this text contain something that must never be emitted?
     *
     * Used by tests and by the pre-export assertion that fails loudly in
     * non-production environments rather than quietly shipping a leak.
     */
    public function containsSensitiveValue(string $text): bool;
}
