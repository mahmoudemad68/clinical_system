<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CSRF for browser cookie sessions and admin login-completion.
 *
 * Device bearer requests never send a CSRF header. Exemption is never based
 * on a client-supplied client_class field (ISR-003): MFA completion reads the
 * stored challenge row, and other pre-auth browser POSTs are detected via
 * Origin / session cookies.
 */
final class ValidateCookieCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
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

        if ($request->headers->has('Origin') || $request->headers->has('Referer')) {
            return false;
        }

        if ($request->cookies->has((string) config('session.cookie', 'clinic_session'))) {
            return false;
        }

        if ($request->user('web') !== null) {
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
