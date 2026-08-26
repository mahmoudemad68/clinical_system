<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Application\ResolveActorContext;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the actor from a device bearer token XOR an admin cookie session.
 * The two schemes are not mixed on one request.
 */
final class AuthenticateActor
{
    public function __construct(private readonly ResolveActorContext $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            $actor = $this->resolver->fromAccessToken($bearer);
            $this->attach($request, $actor);

            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $actor = $this->resolver->fromCookieUser(Identifier::fromTrusted((string) $user->getAuthIdentifier()));
            $this->attach($request, $actor);

            return $next($request);
        }

        throw new AuthenticationFailed;
    }

    private function attach(Request $request, ActorContext $actor): void
    {
        $request->attributes->set(ActorContext::class, $actor);
        $request->attributes->set('actor_id', $actor->userId);
    }
}
