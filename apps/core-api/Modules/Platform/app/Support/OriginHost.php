<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * Production origin host classification (ISR-014).
 *
 * Callers parse URL-style origins first, then pass the parse_url host here.
 * Hostname-only Reverb entries are classified as-is. Brackets are stripped
 * only after a host is already isolated, so a malformed URL cannot be
 * rewritten into an accepted origin.
 */
final class OriginHost
{
    /**
     * Host of a configured CORS URL or Reverb origin entry.
     *
     * URL-style values are parsed; hostname-only values are returned as-is.
     * Returns null when a URL-style value has no usable host.
     */
    public static function fromConfiguredValue(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '') {
            return null;
        }

        if (! str_contains($origin, '://')) {
            return $origin;
        }

        $parts = parse_url($origin);
        if (! is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            return null;
        }

        return $host;
    }

    /**
     * Strip IPv6 brackets and a zone identifier from an already-parsed host.
     */
    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));

        if (strlen($host) >= 2 && str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $zone = strpos($host, '%');
        if ($zone !== false) {
            $host = substr($host, 0, $zone);
        }

        return $host;
    }

    /**
     * True when the host is loopback, unspecified, localhost, or not a
     * classifiable production origin host.
     */
    public static function isDeniedInProduction(string $host): bool
    {
        $normalized = self::normalize($host);

        if ($normalized === '' || str_contains($normalized, '*')) {
            return true;
        }

        if ($normalized === 'localhost' || str_ends_with($normalized, '.localhost')) {
            return true;
        }

        $packed = inet_pton($normalized);
        if ($packed === false) {
            // DNS names cannot contain ":". A colon that is not a valid IP is
            // leftover parse garbage such as ":" from https://::1.
            return str_contains($normalized, ':');
        }

        if (strlen($packed) === 4) {
            return $packed === inet_pton('127.0.0.1') || $packed === inet_pton('0.0.0.0');
        }

        if (strlen($packed) !== 16) {
            return true;
        }

        if ($packed === inet_pton('::1') || $packed === inet_pton('::')) {
            return true;
        }

        $ipv4MappedPrefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
        if (! str_starts_with($packed, $ipv4MappedPrefix)) {
            return false;
        }

        $mappedV4 = substr($packed, 12);

        return $mappedV4 === inet_pton('127.0.0.1') || $mappedV4 === inet_pton('0.0.0.0');
    }
}
