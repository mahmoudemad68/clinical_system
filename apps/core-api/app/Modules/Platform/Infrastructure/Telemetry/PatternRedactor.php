<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Telemetry;

use App\Modules\Platform\Domain\Contracts\Redactor;

/**
 * The single redaction implementation for logs, traces, and error reports.
 *
 * Design notes that matter more than the code:
 *
 *   - Key matching is normalized (lowercased, separators stripped) so
 *     "national_id", "nationalId", and "National-ID" are one rule rather than
 *     three that drift apart.
 *   - Value patterns exist because key matching cannot catch a national ID
 *     typed into a clinical note or an appointment comment. That is the leak
 *     that actually happens in production.
 *   - Depth and breadth are bounded. A redactor that recurses without a limit
 *     turns a malformed payload into a denial of service inside the logging
 *     path, which is the worst place to have one.
 *   - The replacement keeps a type hint ("[redacted:national_id]") so an
 *     engineer reading a log can tell what was removed without seeing it.
 */
final class PatternRedactor implements Redactor
{
    private const REDACTED = '[redacted]';

    private const MAX_DEPTH = 12;

    private const MAX_TEXT_LENGTH = 64_000;

    /**
     * Keys whose values never leave the process, in normalized form.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        // Credentials and secrets
        'password', 'passwd', 'secret', 'token', 'accesstoken', 'refreshtoken',
        'idtoken', 'bearer', 'authorization', 'apikey', 'apisecret', 'privatekey',
        'clientsecret', 'sessionid', 'cookie', 'setcookie', 'csrftoken',
        'signature', 'otp', 'otpcode', 'verificationcode', 'pin', 'mfacode',
        'recoverycode', 'dbpassword', 'encryptionkey', 'hmackey',
        // Direct identifiers
        'nationalid', 'nationalidnumber', 'ssn', 'passportnumber', 'phone',
        'phonenumber', 'mobile', 'msisdn', 'email', 'emailaddress', 'address',
        'street', 'dateofbirth', 'dob', 'fullname', 'firstname', 'lastname',
        // Clinical content
        'clinicalnote', 'clinicalnotes', 'diagnosis', 'diagnoses', 'symptoms',
        'prescriptiontext', 'medicationnotes', 'labresult', 'labresults',
        'labresulttext', 'medicalhistory', 'allergies', 'chroniccondition',
        'chronicconditions', 'chatmessage', 'messagebody', 'reportbody',
        // Storage and AI
        'objectkey', 's3key', 'storagekey', 'signedurl', 'presignedurl',
        'prompt', 'systemprompt', 'completion', 'rawresponse', 'modeloutput',
        'embedding', 'retrievedchunk', 'retrievedchunks',
        // Payment
        'pan', 'cardnumber', 'cvv', 'cvc', 'iban',
    ];

    /**
     * Value patterns, checked against strings regardless of their key.
     *
     * Ordered most specific first so the hint in the replacement is accurate.
     *
     * @var array<string, string>
     */
    private const VALUE_PATTERNS = [
        // Egyptian national ID: 14 digits, not part of a longer run.
        'national_id' => '/(?<![0-9])[0-9]{14}(?![0-9])/',
        // Egyptian mobile: 01X + 8 digits, optionally +20 / 0020 prefixed.
        'phone' => '/(?<![0-9])(?:\+?20|0020)?0?1[0125][0-9]{8}(?![0-9])/',
        // JWT
        'jwt' => '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        // Bearer header value
        'bearer' => '/\bBearer\s+[A-Za-z0-9._~+\/=-]{12,}/i',
        // Laravel/Sanctum style plain-text token: "<id>|<40+ chars>"
        'api_token' => '/\b[0-9]+\|[A-Za-z0-9]{40,}\b/',
        // Common private key blocks
        'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
        // Email
        'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        // Presigned URL query material
        'signed_url' => '/[?&](?:X-Amz-Signature|X-Amz-Credential|Signature|AWSAccessKeyId)=[^&\s]+/i',
        // Payment card numbers, 13 to 19 digits with optional separators.
        'card_number' => '/(?<![0-9])(?:[0-9][ -]?){12,18}[0-9](?![0-9])/',
    ];

    /** @var array<string, true>|null */
    private ?array $sensitiveKeyIndex = null;

    public function redactArray(array $payload): array
    {
        /** @var array<array-key, mixed> $result */
        $result = $this->walk($payload, 0);

        return $result;
    }

    public function redactText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Bound the input. An unbounded regex pass over a huge string inside
        // the logging path is a self-inflicted outage.
        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = substr($text, 0, self::MAX_TEXT_LENGTH).'…[truncated]';
        }

        foreach (self::VALUE_PATTERNS as $hint => $pattern) {
            $replaced = preg_replace($pattern, sprintf('[redacted:%s]', $hint), $text);

            // preg_replace returns null on backtrack limit or a malformed
            // subject. Failing closed here means "emit nothing" rather than
            // "emit the original", because the original is the sensitive one.
            $text = $replaced ?? self::REDACTED;
        }

        return $text;
    }

    public function isSensitiveKey(string $key): bool
    {
        return isset($this->sensitiveKeyIndex()[$this->normalizeKey($key)]);
    }

    public function containsSensitiveValue(string $text): bool
    {
        foreach (self::VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['__truncated' => 'max depth reached'];
        }

        $out = [];

        foreach ($node as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = sprintf('[redacted:%s]', $this->normalizeKey($key));

                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => $this->walk($value, $depth + 1),
                is_string($value) => $this->redactText($value),
                // Objects are not walked. Reflecting into an arbitrary object
                // inside the logging path risks triggering lazy loading or a
                // magic getter with side effects. The class name alone is a
                // safe, useful breadcrumb.
                is_object($value) => sprintf('[object:%s]', $value::class),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * @return array<string, true>
     */
    private function sensitiveKeyIndex(): array
    {
        return $this->sensitiveKeyIndex ??= array_fill_keys(self::SENSITIVE_KEYS, true);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['_', '-', '.', ' '], '', $key));
    }
}
