<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Access\Domain\Contracts\RevokeContextualAccess;
use App\Modules\Access\Domain\Events\AccessGrantRevoked;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class RevokeContextualAccessHandler implements RevokeContextualAccess
{
    public function __construct(
        private readonly GrantStore $grants,
        private readonly Authorize $authorizer,
        private readonly TransactionRunner $transactions,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function revoke(ActorContext $initiator, Identifier $grantId, DateTimeImmutable $now): void
    {
        $decision = $this->authorizer->decide($initiator, Capabilities::ACCESS_GRANT_REVOKE, 'contextual_access_grant', $grantId);
        if (! $decision->allowed) {
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
