<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * CSRF for admin cookie sessions only.
 *
 * Device bearer requests never send a CSRF header. Enforcing CSRF on them would
 * force every Flutter/Electron client to start a cookie session, which mixes the
 * two schemes the phase forbids.
 *
 * Laravel's base class skips CSRF while `runningUnitTests()` is true. That would
 * make the cookie/CSRF suite unable to fail, so this subclass keeps verification
 * on in tests.
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

        if ($request->input('client_class') === 'admin_web') {
            return false;
        }

        return $request->user('web') === null;
    }
}
