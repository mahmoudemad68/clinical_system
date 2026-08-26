<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pending users may only hit the restricted identity surface.
 */
final class DenyPendingBusinessAccess
{
    /** @var list<string> */
    private const ALLOWED = [
        'api.v1.auth.logout',
        'api.v1.auth.sessions.index',
        'api.v1.auth.sessions.destroy',
        'api.v1.auth.sessions.revoke-all',
        'api.v1.auth.token.refresh',
        'api.v1.me.show',
        'api.v1.me.capabilities',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->attributes->get(ActorContext::class);

        if (! $actor instanceof ActorContext) {
            return $next($request);
        }

        if ($actor->status !== AccountStatus::PendingPhone) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if (! is_string($name) || ! in_array($name, self::ALLOWED, true)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
