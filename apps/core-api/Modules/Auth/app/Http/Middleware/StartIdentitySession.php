<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie/admin identity session. Device bearer requests stay stateless: they
 * never start a Laravel session, encrypt cookies, or write Redis session keys.
 * CSRF for cookie clients remains ValidateCookieCsrf.
 */
final class StartIdentitySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $next($request);
        }

        return app(EncryptCookies::class)->handle(
            $request,
            function (Request $request) use ($next): Response {
                return app(AddQueuedCookiesToResponse::class)->handle(
                    $request,
                    function (Request $request) use ($next): Response {
                        return app(StartSession::class)->handle(
                            $request,
                            function (Request $request) use ($next): Response {
                                return app(ValidateCookieCsrf::class)->handle($request, $next);
                            },
                        );
                    },
                );
            },
        );
    }
}
