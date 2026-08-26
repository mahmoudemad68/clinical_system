<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Identity\Application\ResolveActorContext;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Exceptions\AuthenticationFailed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device bearer authentication. Cookie sessions use AuthenticateActor.
 */
final class AuthenticateDevice
{
    public function __construct(private readonly ResolveActorContext $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            throw new AuthenticationFailed;
        }

        $actor = $this->resolver->fromAccessToken($token);
        $request->attributes->set(ActorContext::class, $actor);
        $request->attributes->set('actor_id', $actor->userId);

        return $next($request);
    }
}
