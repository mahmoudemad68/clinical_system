<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Identity\Domain\ValueObjects\AssuranceLevel;
use App\Modules\Platform\Domain\Contracts\HmacHasher;
use App\Modules\Platform\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class ResolveActorContext
{
    public function __construct(
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly HmacHasher $hmac,
    ) {}

    public function fromAccessToken(string $token): ActorContext
    {
        $hash = $this->hmac->digest('session_token', $token);
        $device = $this->auth->findDeviceByAccessHash($hash);

        if ($device === null) {
            throw new AuthenticationFailed;
        }

        if ($device->expires_at !== null && new DateTimeImmutable((string) $device->expires_at) <= new DateTimeImmutable('now')) {
            throw new AuthenticationFailed;
        }

        $user = $this->identities->findById(Identifier::fromTrusted((string) $device->user_id));

        if ($user === null || $user->credentialVersion !== (int) $device->credential_version || ! $user->status->canReceiveDeviceSession()) {
            throw new AuthenticationFailed;
        }

        $session = $this->auth->findSessionByHash($hash);

        return $this->context(
            $user,
            $session !== null ? Identifier::fromTrusted((string) $session->id) : null,
            Identifier::fromTrusted((string) $device->id),
            $session !== null ? AssuranceLevel::from((string) $session->assurance_level) : AssuranceLevel::Aal1Password,
        );
    }

    public function fromUserId(Identifier $userId, ?Identifier $sessionId, AssuranceLevel $assurance): ActorContext
    {
        $user = $this->identities->findById($userId);

        if ($user === null) {
            throw new AuthenticationFailed;
        }

        return $this->context($user, $sessionId, null, $assurance);
    }

    public function fromCookieUser(Identifier $userId): ActorContext
    {
        $user = $this->identities->findById($userId);

        if ($user === null || ! $user->status->canReceiveDeviceSession()) {
            throw new AuthenticationFailed;
        }

        $session = $this->auth->latestCookieSession($userId);

        if ($session === null) {
            throw new AuthenticationFailed;
        }

        $now = new DateTimeImmutable('now');
        if (new DateTimeImmutable((string) $session->absolute_expires_at) <= $now) {
            throw new AuthenticationFailed;
        }

        if ($session->idle_expires_at !== null && new DateTimeImmutable((string) $session->idle_expires_at) <= $now) {
            throw new AuthenticationFailed;
        }

        if ((int) $session->credential_version !== $user->credentialVersion) {
            throw new AuthenticationFailed;
        }

        return $this->context(
            $user,
            Identifier::fromTrusted((string) $session->id),
            null,
            AssuranceLevel::from((string) $session->assurance_level),
        );
    }

    private function context(UserAccount $user, ?Identifier $sessionId, ?Identifier $deviceId, AssuranceLevel $assurance): ActorContext
    {
        return new ActorContext(
            $user->id,
            $user->accountType,
            $user->status,
            $user->language,
            $assurance,
            $user->credentialVersion,
            $deviceId,
            $sessionId,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );
    }
}
