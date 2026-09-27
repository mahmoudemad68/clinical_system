<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

/**
 * Canonical Phase 02 HTTP/IPC surface derived from the same sources
 * the implementation uses: routes/api.php and desktop_bridge_contracts.
 */
final class Phase02ImplementedSurface
{
    /**
     * @return list<string>
     */
    public static function httpIdentitiesFromApiPhp(string $php): array
    {
        $identities = [];
        if (preg_match_all(
            '/(?:Route::|->)(get|post|patch|put|delete)\(\s*\'(\/[^\'\s]+)\'/i',
            $php,
            $matches,
            PREG_SET_ORDER,
        ) === false) {
            return $identities;
        }

        foreach ($matches as $match) {
            $method = strtoupper($match[1]);
            $path = '/api/v1'.$match[2];
            if (self::isPhase02Path($path)) {
                $identities[] = $method.' '.$path;
            }
        }

        $identities = array_values(array_unique($identities));
        sort($identities);

        return $identities;
    }

    public static function isPhase02Path(string $path): bool
    {
        $needles = [
            '/api/v1/patients/',
            '/api/v1/doctors/',
            '/api/v1/pharmacy-organizations',
            '/api/v1/pharmacy-staff-invitations',
            '/api/v1/clinic-locations',
            '/api/v1/clinic-staff-invitations',
            '/api/v1/verification-uploads',
            '/api/v1/verification-review-files',
            '/api/v1/admin/doctor-applicants',
            '/api/v1/admin/verification-cases',
        ];
        foreach ($needles as $needle) {
            if ($path === rtrim($needle, '/') || str_starts_with($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function channelsFromContract(string $typescript, string $prefix): array
    {
        $channels = [];
        $pattern = '/[\'"]('.preg_quote($prefix, '/').'[^\'"]+)[\'"]/';
        if (preg_match_all($pattern, $typescript, $matches) === false) {
            return $channels;
        }
        foreach ($matches[1] as $channel) {
            $channels[] = $channel;
        }
        $channels = array_values(array_unique($channels));
        sort($channels);

        return $channels;
    }

    /**
     * @param  iterable<mixed>  $routes
     * @return list<string>
     */
    public static function httpIdentitiesFromLaravelRoutes(iterable $routes): array
    {
        $identities = [];
        foreach ($routes as $route) {
            if (! is_object($route) || ! method_exists($route, 'uri') || ! method_exists($route, 'methods')) {
                continue;
            }
            $uri = '/'.ltrim((string) $route->uri(), '/');
            if (! str_starts_with($uri, '/api/')) {
                $uri = '/api/'.ltrim($uri, '/');
            }
            if (! self::isPhase02Path($uri)) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $method = strtoupper((string) $method);
                if (in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                    $identities[] = $method.' '.$uri;
                }
            }
        }
        $identities = array_values(array_unique($identities));
        sort($identities);

        return $identities;
    }
}
