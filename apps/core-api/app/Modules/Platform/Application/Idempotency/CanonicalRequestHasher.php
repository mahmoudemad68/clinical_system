<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Idempotency;

/**
 * Canonical hash of a mutation's intent (idempotency contract step 3).
 *
 * Covers method, path, and body. Deliberately excludes headers and query
 * ordering noise: two byte-identical intents must hash the same even if a
 * proxy added a header on the retry.
 *
 * Lives under Application/Idempotency so deptrac does not classify this
 * class as the Http layer (that collector is the module-level Http tree).
 */
final class CanonicalRequestHasher
{
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

        $this->ksortRecursive($decoded);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
