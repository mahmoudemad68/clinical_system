<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Events\CredentialVersionChanged;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Events\StatusChanged;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;

/**
 * Locks, suspends, or closes an identity and revokes credentials.
 *
 * Listed in ApprovedCoordinators: Identity status plus Auth session/device rows
 * must change in one transaction.
 */
final class DisableIdentityService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly Authorize $authorizer,
        private readonly RecordPrivilegedFailure $privilegedFailures,
    ) {}

    public function handle(ActorContext $initiator, Identifier $userId, AccountStatus $target, string $reasonCode): void
    {
        $decision = $this->authorizer->decide($initiator, Capabilities::IDENTITY_DISABLE, 'user', $userId);
        if (! $decision->allowed) {
            $this->privilegedFailures->authorizationDenied(
                $initiator->userId,
                $initiator->accountType->value,
                $initiator->assuranceLevel->value,
                Capabilities::IDENTITY_DISABLE,
                $decision->reasonCode,
                $userId,
                'user',
            );
            throw new AuthorizationDenied;
        }

        if ($target === AccountStatus::PendingPhone || $target === AccountStatus::Active) {
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($initiator, $userId, $target, $reasonCode): void {
            $user = $this->identities->lockById($userId);
            if ($user === null) {
                throw new AuthorizationDenied;
            }

            if ($user->status === $target) {
                return;
            }

            $now = $this->clock->now();
            $version = $user->credentialVersion + 1;
            $this->identities->updateStatus($user->id, $target, $version, $now);
            $this->auth->revokeAllSessions($user->id, $reasonCode, $now);
            $this->auth->revokeAllDevices($user->id, $reasonCode, $now);
            $tx->recordEvent(new StatusChanged($user->id, $user->status->value, $target->value, $reasonCode, $now));
            $tx->recordEvent(new CredentialVersionChanged($user->id, $version, $reasonCode, $now));
            $this->audit->append($tx, 'identity.status_changed', 'user', $user->id, ['reason_code' => $reasonCode], $initiator->userId, 'user');
        });
    }
}
