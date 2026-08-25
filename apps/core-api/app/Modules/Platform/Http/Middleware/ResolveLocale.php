<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates the response language from Accept-Language.
 *
 * Arabic and English only (plan.md section 148). The negotiated locale drives
 * error messages and health text, so a patient sees Arabic without every client
 * maintaining its own copy of the error catalogue.
 *
 * The header is untrusted input. Only tags on the supported list are honoured,
 * matched on the primary subtag so "ar-EG" resolves to "ar"; anything else
 * falls back. An unbounded or unexpected value must never reach the translator
 * or become a cache key component.
 */
final class ResolveLocale
{
    private const MAX_HEADER_LENGTH = 128;

    /**
     * @param  list<string>  $supported
     */
    public function __construct(
        private readonly array $supported,
        private readonly string $fallback,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->negotiate($request->headers->get('Accept-Language'));

        app()->setLocale($locale);
        $request->attributes->set('locale', $locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        // The response varies by this header, so a shared cache must not serve
        // an Arabic body to an English client.
        $response->headers->set('Vary', 'Accept-Language');

        return $response;
    }

    private function negotiate(?string $header): string
    {
        if (! is_string($header) || $header === '' || strlen($header) > self::MAX_HEADER_LENGTH) {
            return $this->fallback;
        }

        // Parse "ar-EG,ar;q=0.9,en;q=0.8" into quality-ordered primary subtags.
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = strtolower(trim($segments[0]));

            if ($tag === '') {
                continue;
            }

            $quality = 1.0;
            if (isset($segments[1]) && preg_match('/q=([0-9.]+)/', $segments[1], $m) === 1) {
                $quality = (float) $m[1];
            }

            $primary = explode('-', $tag)[0];

            // Keep the highest quality seen for each primary subtag.
            if (! isset($candidates[$primary]) || $candidates[$primary] < $quality) {
                $candidates[$primary] = $quality;
            }
        }

        arsort($candidates);

        foreach (array_keys($candidates) as $primary) {
            if (in_array($primary, $this->supported, true)) {
                return $primary;
            }
        }

        return $this->fallback;
    }
}
