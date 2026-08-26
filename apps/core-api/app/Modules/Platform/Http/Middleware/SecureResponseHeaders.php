<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secure response headers (mandatory Phase 00 control).
 *
 * API JSON responses render nothing, so their CSP denies every fetch.
 * First-party Inertia pages need a same-origin policy that still forbids
 * framing, objects, and eval. Telescope ships its own assets and is local-only.
 */
final class SecureResponseHeaders
{
    private const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    private const WEB_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'";

    private const WEB_DEV_CONNECT = ' http://127.0.0.1:5173 http://localhost:5173 ws://127.0.0.1:5173 ws://localhost:5173';

    public function __construct(
        private readonly bool $enableHsts,
        private readonly bool $viteDevConnect = false,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('telescope*')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        );
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        if ($this->enableHsts && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        if ($request->is('api/*')) {
            return self::API_CSP;
        }

        if ($this->viteDevConnect) {
            return str_replace(
                "connect-src 'self'",
                "connect-src 'self'".self::WEB_DEV_CONNECT,
                self::WEB_CSP,
            );
        }

        return self::WEB_CSP;
    }
}
