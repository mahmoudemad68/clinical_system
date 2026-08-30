<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use DateTimeImmutable;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Contracts\RevokeContextualAccess;
use Modules\Access\Events\AccessGrantRevoked;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;

final class RevokeContextualAccessService implements RevokeContextualAccess
{
    public function __construct(
        private readonly GrantStore $grants,
        private readonly Authorize $authorizer,
        private readonly TransactionRunner $transactions,
        private readonly AppendAuditEvent $audit,
        private readonly RecordPrivilegedFailure $privilegedFailures,
    ) {}

    public function revoke(ActorContext $initiator, Identifier $grantId, DateTimeImmutable $now): void
    {
        $decision = $this->authorizer->decide($initiator, Capabilities::ACCESS_GRANT_REVOKE, 'contextual_access_grant', $grantId);
        if (! $decision->allowed) {
            $this->privilegedFailures->authorizationDenied(
                $initiator->userId,
                $initiator->accountType->value,
                $initiator->assuranceLevel->value,
                Capabilities::ACCESS_GRANT_REVOKE,
                $decision->reasonCode,
                $grantId,
                'contextual_access_grant',
            );
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($initiator, $grantId, $now): void {
            $row = $this->grants->find($grantId);
            if ($row === null) {
                throw new AuthorizationDenied;
            }

            $subject = Identifier::fromTrusted($row['actor_user_id']);
            $this->grants->revoke($grantId, $now);
            $tx->recordEvent(new AccessGrantRevoked($grantId, $subject, 'operator_revoke', $now));
            $this->audit->append(
                $tx,
                'access.grant_revoked',
                'contextual_access_grant',
                $grantId,
                ['reason_code' => 'operator_revoke'],
                $initiator->userId,
                'user',
            );
        });
    }
}
