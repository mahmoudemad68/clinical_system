<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Events\CredentialVersionChanged;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\Events\StatusChanged;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Locks, suspends, or closes an identity and revokes credentials.
 *
 * Listed in ApprovedCoordinators: Identity status plus Auth session/device rows
 * must change in one transaction.
 */
final class DisableIdentityCoordinator
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function handle(Identifier $userId, AccountStatus $target, string $reasonCode): void
    {
        if ($target === AccountStatus::PendingPhone || $target === AccountStatus::Active) {
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($userId, $target, $reasonCode): void {
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
            $this->audit->append($tx, 'identity.status_changed', 'user', $user->id, ['reason_code' => $reasonCode], $user->id, 'user');
        });
    }
}
