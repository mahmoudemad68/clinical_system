<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Modules\Auth\Services\AuthorizePrivateSessionChannel;
use Modules\Identity\Support\ActorContext;

/*
|--------------------------------------------------------------------------
| Broadcast channels — deny by default
|--------------------------------------------------------------------------
|
| Phase 00 scaffolds private-channel authorization. No client is authorized
| to subscribe until Phase 04 supplies actor-scoped queue channels. A
| channel name is not authorization (invariant 13).
|
| Session disconnect is bound to the exact presenting auth_sessions row.
| Same-user ownership of another session is not sufficient. The Reverb
| process closes subscribed sockets when that row is revoked.
|
*/

Broadcast::channel('platform.health', static fn (): bool => false);

Broadcast::channel('auth.session.{sessionId}', static function ($user, string $sessionId): bool {
    $actor = request()->attributes->get(ActorContext::class);
    if (! $actor instanceof ActorContext) {
        return false;
    }

    return app(AuthorizePrivateSessionChannel::class)->allows($actor, $sessionId);
});
