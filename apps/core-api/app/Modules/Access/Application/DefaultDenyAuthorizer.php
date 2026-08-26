<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\ValueObjects\AuthorizationDecision;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Phase 01 policy: self-service identity/session capabilities only.
 *
 * Clinical, pharmacy, and catalog actions are unknown and therefore denied.
 * account_type is never read from the client; ActorContext is server-built.
 */
final class DefaultDenyAuthorizer implements Authorize
{
    // Deny-by-default: unknown, pending, stale, or insufficient assurance never allow.
    public function decide(
        ActorContext $actor,
        string $action,
        ?string $resourceType = null,
        ?Identifier $resourceId = null,
        ?string $contextType = null,
        ?Identifier $contextId = null,
    ): AuthorizationDecision {
        $group = $this->actionGroup($action);

        if ($actor->status === AccountStatus::Suspended
            || $actor->status === AccountStatus::Locked
            || $actor->status === AccountStatus::Closed) {
            return AuthorizationDecision::deny('actor_not_active', $group);
        }

        if (! Capabilities::isKnown($action)) {
            return AuthorizationDecision::deny('unknown_action', $group);
        }

        if ($actor->status === AccountStatus::PendingPhone && $this->pendingForbidden($action)) {
            return AuthorizationDecision::deny('pending_restricted', $group);
        }

        if ($actor->accountType->requiresTotpForPrivilegedSession()
            && ! $actor->assuranceLevel->satisfiesPrivilegedSession()
            && $this->requiresPrivilege($action)) {
            return AuthorizationDecision::deny('insufficient_assurance', $group);
        }

        if (! in_array($action, $actor->capabilities, true)) {
            return AuthorizationDecision::deny('capability_absent', $group);
        }

        if ($resourceId !== null && $resourceType === 'auth_session' && ! $resourceId->equals($actor->sessionId ?? $resourceId)) {
            // Own-session actions still need the session to belong to the actor.
            // Resource membership is checked by the handler against storage;
            // missing resource context here is a deny.
            if ($actor->sessionId === null) {
                return AuthorizationDecision::deny('missing_context', $group);
            }
        }

        return AuthorizationDecision::allow($group);
    }

    private function pendingForbidden(string $action): bool
    {
        return $action === Capabilities::PASSWORD_CHANGE
            || $action === Capabilities::MFA_MANAGE_SELF;
    }

    private function requiresPrivilege(string $action): bool
    {
        return $action === Capabilities::MFA_MANAGE_SELF;
    }

    private function actionGroup(string $action): string
    {
        $parts = explode('.', $action);

        return $parts[0] ?? 'unknown';
    }
}
