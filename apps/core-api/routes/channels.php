<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Platform\Support\Identifier;

/*
|--------------------------------------------------------------------------
| Broadcast channels — deny by default
|--------------------------------------------------------------------------
|
| Phase 00 scaffolds private-channel authorization. No client is authorized
| to subscribe until Phase 04 supplies actor-scoped queue channels. A
| channel name is not authorization (invariant 13).
|
| Session disconnect is bound to the exact auth_sessions row. Measured
| socket-close SLO remains G-01-16 OPEN.
|
*/

Broadcast::channel('platform.health', static fn (): bool => false);

Broadcast::channel('auth.session.{sessionId}', static function ($user, string $sessionId): bool {
    if ($user === null || $sessionId === '') {
        return false;
    }

    try {
        $session = app(AuthDirectory::class)->findSession(Identifier::fromString($sessionId));
    } catch (Throwable) {
        return false;
    }

    return $session !== null
        && $session->revoked_at === null
        && (string) $session->user_id === (string) $user->getAuthIdentifier()
        && (string) $session->id === $sessionId;
});
