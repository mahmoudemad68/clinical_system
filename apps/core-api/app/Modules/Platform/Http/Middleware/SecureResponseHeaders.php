<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secure response headers (mandatory Phase 00 control).
 *
 * These are cheap and they close real classes of attack, so they are applied
 * unconditionally rather than per-route.
 *
 * The API returns JSON only, so the CSP is maximally restrictive: nothing
 * should ever be loaded from an API response. The admin web application ships
 * its own, looser policy from its own server; this one must not be copied
 * there, because a policy that permits a UI to work is a different document.
 */
final class SecureResponseHeaders
{
    public function __construct(private readonly bool $enableHsts)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A JSON API renders nothing. Deny everything.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        );

        // Stop a browser from second-guessing the declared content type, which
        // is how a JSON response becomes executable script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        // No cross-origin referrer leakage of API paths, which can carry
        // identifiers.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Nothing here needs camera, microphone, or geolocation.
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        );

        // API responses are per-actor. A shared cache must never retain them.
        $response->headers->set('Cache-Control', 'no-store, private');

        // Do not advertise the stack.
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // HSTS only where TLS actually terminates. Sending it over plain HTTP
        // in local development would pin localhost to https in the developer's
        // browser, which is a genuinely annoying thing to undo.
        if ($this->enableHsts && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
