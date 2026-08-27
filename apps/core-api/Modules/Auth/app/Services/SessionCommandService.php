<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Events\CredentialVersionChanged;
use Modules\Auth\Events\SessionRevoked;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthenticationFailed;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;

final class SessionCommandService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly Authorize $authorize,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ActorContext $actor): array
    {
        $decision = $this->authorize->decide($actor, Capabilities::SESSION_LIST_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $rows = $this->auth->listSessions($actor->userId);
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'session_id' => $row->id,
                'session_kind' => $row->session_kind,
                'assurance_level' => $row->assurance_level,
                'platform' => $row->device_platform ?? null,
                'device_label' => $row->device_label ?? null,
                'last_seen_at' => $row->last_seen_at,
                'created_at' => $row->created_at,
            ];
        }

        return $out;
    }

    public function logoutCurrent(ActorContext $actor): void
    {
        $this->transactions->run(function (TransactionContext $tx) use ($actor): void {
            $now = $this->clock->now();
            $sessionId = $actor->sessionId;
            $deviceId = $actor->deviceId;

            if ($sessionId === null && $deviceId !== null) {
                $row = $this->auth->findActiveSessionByDevice($deviceId);
                $sessionId = $row !== null ? Identifier::fromTrusted((string) $row->id) : null;
            }

            if ($sessionId === null && $deviceId === null) {
                throw new AuthenticationFailed;
            }

            if ($sessionId !== null) {
                $this->auth->revokeSession($sessionId, 'user_logout', $now);
            }
            if ($deviceId !== null) {
                $this->auth->revokeDevice($deviceId, 'user_logout', $now);
                $this->auth->revokeSessionsForDevice($deviceId, 'user_logout', $now);
            }

            $eventId = $sessionId ?? $deviceId;
            if ($eventId === null) {
                throw new AuthenticationFailed;
            }

            $tx->recordEvent(new SessionRevoked($actor->userId, $eventId, 'user_logout', $now));
            $this->audit->append($tx, 'auth.session_revoked', 'auth_session', $eventId, ['reason_code' => 'user_logout'], $actor->userId, 'user');
        });
    }

    public function revoke(ActorContext $actor, string $sessionId): void
    {
        $decision = $this->authorize->decide($actor, Capabilities::SESSION_REVOKE_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($actor, $sessionId): void {
            $session = $this->auth->findSession(Identifier::fromString($sessionId));
            if ($session === null || (string) $session->user_id !== $actor->userId->value) {
                throw new AuthorizationDenied;
            }

            $now = $this->clock->now();
            $id = Identifier::fromTrusted((string) $session->id);
            $this->auth->revokeSession($id, 'user_revoke', $now);
            if ($session->device_id !== null) {
                $this->auth->revokeDevice(Identifier::fromTrusted((string) $session->device_id), 'user_revoke', $now);
            }
            $tx->recordEvent(new SessionRevoked($actor->userId, $id, 'user_revoke', $now));
            $this->audit->append($tx, 'auth.session_revoked', 'auth_session', $id, ['reason_code' => 'user_revoke'], $actor->userId, 'user');
        });
    }

    public function revokeAll(ActorContext $actor): void
    {
        $decision = $this->authorize->decide($actor, Capabilities::SESSION_REVOKE_ALL);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($actor): void {
            $now = $this->clock->now();
            $this->auth->revokeAllSessions($actor->userId, 'revoke_all', $now);
            $this->auth->revokeAllDevices($actor->userId, 'revoke_all', $now);
            $tx->recordEvent(new SessionRevoked($actor->userId, $actor->sessionId ?? $actor->userId, 'revoke_all', $now));
            $this->audit->append($tx, 'auth.sessions_revoked_all', 'user', $actor->userId, ['reason_code' => 'revoke_all'], $actor->userId, 'user');
        });
    }

    public function changePassword(ActorContext $actor, string $current, string $next, PasswordHasher $hasher, PasswordPolicy $policy): void
    {
        $decision = $this->authorize->decide($actor, Capabilities::PASSWORD_CHANGE);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($actor, $current, $next, $hasher, $policy): void {
            $user = $this->identities->lockById($actor->userId);
            if ($user === null || ! $hasher->verify($current, $user->passwordHash)) {
                throw new AuthorizationDenied;
            }

            $policy->assert($next);
            $version = $user->credentialVersion + 1;
            $now = $this->clock->now();
            $this->identities->replacePassword($user->id, $hasher->hash($next), $version, $now);
            $this->auth->revokeAllSessions($user->id, 'password_change', $now);
            $this->auth->revokeAllDevices($user->id, 'password_change', $now);
            $tx->recordEvent(new CredentialVersionChanged($user->id, $version, 'password_change', $now));
            $this->audit->append($tx, 'auth.password_changed', 'user', $user->id, ['reason_code' => 'password_change'], $user->id, 'user');
        });
    }
}
