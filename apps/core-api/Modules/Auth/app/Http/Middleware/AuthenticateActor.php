<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Identity\Services\ResolveActorContext;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Exceptions\AuthenticationFailed;
use Modules\Platform\Support\Identifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the actor from a device bearer token XOR an admin cookie session.
 * The two schemes are not mixed on one request.
 *
 * Cookie API auth is HMAC-primary: the bound `cookie:{laravelSessionId}` hash
 * is enough. `login_web_*` is not required. If Laravel rotated the session id
 * while the web guard still has a user, the latest admin cookie row is rebound
 * once onto the current id.
 */
final class AuthenticateActor
{
    public function __construct(private readonly ResolveActorContext $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            $actor = $this->resolver->fromAccessToken($bearer);
            $this->attachContext($request, $actor);

            return $next($request);
        }

        $laravelSessionId = (string) $request->session()->getId();

        try {
            $actor = $this->resolver->fromLaravelCookieSession($laravelSessionId);
            $this->attach($request, $actor);

            return $next($request);
        } catch (AuthenticationFailed $hmacMiss) {
            $user = Auth::guard('web')->user();
            if (! $user instanceof User) {
                $this->traceCookieMiss($request, $laravelSessionId, webUser: false);

                throw $hmacMiss;
            }

            try {
                $userId = Identifier::fromTrusted((string) $user->getAuthIdentifier());
                $this->resolver->rebindCookieSessionHash($userId, $laravelSessionId);
                $actor = $this->resolver->fromCookieUser($userId, $laravelSessionId);
                $this->attach($request, $actor);

                return $next($request);
            } catch (AuthenticationFailed) {
                $this->traceCookieMiss($request, $laravelSessionId, webUser: true);

                throw $hmacMiss;
            }
        }
    }

    private function attach(Request $request, ActorContext $actor): void
    {
        $this->attachContext($request, $actor);

        $user = User::query()->find($actor->userId->value);
        if ($user instanceof User) {
            Auth::guard('web')->setUser($user);
        }
    }

    private function attachContext(Request $request, ActorContext $actor): void
    {
        $request->attributes->set(ActorContext::class, $actor);
        $request->attributes->set('actor_id', $actor->userId);

        $user = new User;
        $user->setRawAttributes(['id' => $actor->userId->value], true);
        $user->exists = true;
        Auth::guard('web')->setUser($user);
    }

    private function traceCookieMiss(Request $request, string $laravelSessionId, bool $webUser): void
    {
        if ((string) config('app.env') !== 'testing') {
            return;
        }

        $hmac = 0;
        if ($laravelSessionId !== '') {
            $hash = app(HmacHasher::class)->digest('session_token', 'cookie:'.$laravelSessionId);
            $hmac = app(AuthDirectory::class)->findSessionByHash($hash) !== null ? 1 : 0;
        }

        error_log(sprintf(
            'auth.actor miss method=%s user=%d hmac=%d cookie=%d xsrf=%d',
            $request->method(),
            $webUser ? 1 : 0,
            $hmac,
            $request->cookies->has((string) config('session.cookie')) ? 1 : 0,
            $request->headers->has('X-XSRF-TOKEN') || $request->headers->has('X-CSRF-TOKEN') ? 1 : 0,
        ));
    }
}
