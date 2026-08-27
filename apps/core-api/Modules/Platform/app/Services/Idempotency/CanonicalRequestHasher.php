<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Idempotency;

/**
 * Canonical hash of a mutation's secret-free intent (idempotency contract).
 *
 * Covers method, path, and a projected body. Passwords, OTP codes, refresh
 * tokens, and National IDs are stripped before hashing so a database reader
 * cannot use request_hash as a fast offline credential oracle (ISR-004).
 */
final class CanonicalRequestHasher
{
    /** @var list<string> */
    private const SECRET_KEYS = [
        'password',
        'current_password',
        'new_password',
        'code',
        'totp_code',
        'recovery_code',
        'refresh_token',
        'access_token',
        'national_id',
        'nationalId',
    ];

    public function hash(string $method, string $path, string $body): string
    {
        $normalizedBody = $this->normalizeBody($body);

        return hash('sha256', implode("\0", [
            strtoupper($method),
            $path,
            $normalizedBody,
        ]));
    }

    private function normalizeBody(string $body): string
    {
        $decoded = json_decode($body === '' ? '{}' : $body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        $decoded = $this->stripSecrets($decoded);
        $this->ksortRecursive($decoded);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function stripSecrets(array $payload): array
    {
        foreach (self::SECRET_KEYS as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripSecrets($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
