<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use DateTimeImmutable;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Support\Identifier;
use Throwable;

/**
 * Private session channels are bound to the presenting auth session, not to
 * "same user owns that row".
 */
final class AuthorizePrivateSessionChannel
{
    public function __construct(
        private readonly AuthDirectory $auth,
        private readonly Clock $clock,
    ) {}

    public function allows(?ActorContext $actor, string $sessionId): bool
    {
        if ($actor === null || $actor->sessionId === null || $sessionId === '') {
            return false;
        }

        try {
            $channelSessionId = Identifier::fromString($sessionId);
        } catch (Throwable) {
            return false;
        }

        if (! $actor->sessionId->equals($channelSessionId)) {
            return false;
        }

        $session = $this->auth->findSession($channelSessionId);
        if ($session === null || $session->revoked_at !== null) {
            return false;
        }

        if ((string) $session->id !== $channelSessionId->value) {
            return false;
        }

        if ((string) $session->user_id !== $actor->userId->value) {
            return false;
        }

        $now = $this->clock->now();
        if (! $this->isUnexpired($session->absolute_expires_at ?? null, $now)) {
            return false;
        }

        if (isset($session->idle_expires_at) && ! $this->isUnexpired($session->idle_expires_at, $now)) {
            return false;
        }

        return (int) $session->credential_version === $actor->credentialVersion;
    }

    private function isUnexpired(mixed $expiresAt, DateTimeImmutable $now): bool
    {
        if (! is_string($expiresAt) && ! $expiresAt instanceof DateTimeImmutable) {
            return false;
        }

        $expires = $expiresAt instanceof DateTimeImmutable
            ? $expiresAt
            : new DateTimeImmutable($expiresAt);

        return $expires > $now;
    }
}
