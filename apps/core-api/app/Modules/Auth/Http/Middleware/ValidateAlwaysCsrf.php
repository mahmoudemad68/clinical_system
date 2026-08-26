<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Web-group CSRF. Always evaluated, including under Pest (ISR-003).
 *
 * Fetch Metadata (`Sec-Fetch-Site: same-origin`) is not treated as a substitute
 * for a CSRF token on cookie sessions. Device API login stays on
 * {@see ValidateCookieCsrf}, which skips CSRF when no session or XSRF cookie
 * is present so Electron bearer login is not Origin-forced.
 */
final class ValidateAlwaysCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }

    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
