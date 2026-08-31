<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CSRF for browser cookie sessions and admin login-completion.
 *
 * Device bearer requests never send a CSRF header. Exemption is never based
 * on a client-supplied client_class field (ISR-003): MFA completion reads the
 * stored challenge row, and other pre-auth browser POSTs are detected via
 * session or XSRF cookies rather than a bare Origin header (Electron net.fetch).
 */
final class ValidateCookieCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }

    protected function hasValidOrigin($request): bool
    {
        return false;
    }

    protected function inExceptArray($request): bool
    {
        if (parent::inExceptArray($request)) {
            return true;
        }

        if (! $request instanceof Request) {
            return true;
        }

        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return true;
        }

        if ($this->isCookieClassMfaCompletion($request)) {
            return false;
        }

        $sessionCookie = (string) config('session.cookie', 'clinic_session');
        $rawCookie = (string) $request->headers->get('Cookie', '');
        $hasSessionCookie = $request->cookies->has($sessionCookie)
            || preg_match('/(?:^|;\\s*)'.preg_quote($sessionCookie, '/').'=/', $rawCookie) === 1;
        $hasXsrfCookie = $request->cookies->has('XSRF-TOKEN')
            || preg_match('/(?:^|;\\s*)XSRF-TOKEN=/', $rawCookie) === 1;
        if ($hasSessionCookie || $hasXsrfCookie || $request->user('web') !== null) {
            return false;
        }

        return true;
    }

    private function isCookieClassMfaCompletion(Request $request): bool
    {
        if (! $request->is('api/v1/auth/mfa/challenges/*/verify') && ! $request->routeIs('api.v1.auth.mfa.verify')) {
            return false;
        }

        $id = $request->route('id');
        if (! is_string($id) || $id === '') {
            return false;
        }

        $class = DB::table('mfa_challenges')->where('id', $id)->value('client_class');

        return $class === 'admin_web';
    }
}
