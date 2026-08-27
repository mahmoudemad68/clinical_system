<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\Contracts\GrantStore;
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
 * Contextual grants never confer operator privileges and must match resource.
 */
final class DefaultDenyAuthorizer implements Authorize
{
    public function __construct(private readonly GrantStore $grants) {}

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

        if ($this->requiresPrivilege($action)
            && ($actor->accountType->value !== 'admin' || ! $actor->assuranceLevel->satisfiesPrivilegedSession())) {
            return AuthorizationDecision::deny('insufficient_assurance', $group);
        }

        if (Capabilities::isGrantable($action)) {
            if ($resourceType === null || $resourceId === null || $contextType === null || $contextId === null) {
                return AuthorizationDecision::deny('missing_context', $group);
            }

            if ($this->grants->findActive($actor->userId, $action, $resourceType, $resourceId, $contextType, $contextId) === null) {
                return AuthorizationDecision::deny('capability_absent', $group);
            }

            return AuthorizationDecision::allow($group);
        }

        if (! in_array($action, $actor->capabilities, true)) {
            return AuthorizationDecision::deny('capability_absent', $group);
        }

        if (in_array($action, [Capabilities::SESSION_LIST_OWN, Capabilities::SESSION_REVOKE_OWN], true)
            && $resourceType === 'auth_session'
            && $resourceId !== null
            && ($actor->sessionId === null || ! $resourceId->equals($actor->sessionId))) {
            return AuthorizationDecision::deny('capability_absent', $group);
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
        return in_array($action, Capabilities::PRIVILEGED_OPERATOR, true);
    }

    private function actionGroup(string $action): string
    {
        $parts = explode('.', $action);

        return $parts[0] ?? 'unknown';
    }
}
