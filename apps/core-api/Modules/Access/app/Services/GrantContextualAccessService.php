<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use DateTimeImmutable;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantContextualAccess;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Events\AccessGrantIssued;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

final class GrantContextualAccessService implements GrantContextualAccess
{
    public function __construct(
        private readonly GrantStore $grants,
        private readonly IdentityGenerator $ids,
        private readonly Authorize $authorizer,
        private readonly TransactionRunner $transactions,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function grant(
        ActorContext $initiator,
        Identifier $subjectUserId,
        string $capability,
        string $resourceType,
        Identifier $resourceId,
        string $contextType,
        Identifier $contextId,
        string $reasonCode,
        DateTimeImmutable $now,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ): Identifier {
        $decision = $this->authorizer->decide(
            $initiator,
            Capabilities::ACCESS_GRANT_ISSUE,
            $resourceType,
            $resourceId,
            $contextType,
            $contextId,
        );
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        if (! Capabilities::isGrantable($capability) || ! Capabilities::isGrantableResourceType($resourceType)) {
            throw new InvalidValueObject('The grant is not allowed for that capability or resource.');
        }

        return $this->transactions->run(function (TransactionContext $tx) use (
            $initiator,
            $subjectUserId,
            $capability,
            $resourceType,
            $resourceId,
            $contextType,
            $contextId,
            $reasonCode,
            $now,
            $validFrom,
            $validUntil,
        ): Identifier {
            $existing = $this->grants->findActive(
                $subjectUserId,
                $capability,
                $resourceType,
                $resourceId,
                $contextType,
                $contextId,
            );

            if ($existing !== null) {
                return $existing;
            }

            $id = $this->ids->next();

            try {
                $this->grants->insert(
                    $id,
                    $subjectUserId,
                    $capability,
                    $resourceType,
                    $resourceId,
                    $contextType,
                    $contextId,
                    $reasonCode,
                    'user',
                    $initiator->userId,
                    $now,
                    $validFrom,
                    $validUntil,
                );
            } catch (DuplicateIdentity) {
                $winner = $this->grants->findActive(
                    $subjectUserId,
                    $capability,
                    $resourceType,
                    $resourceId,
                    $contextType,
                    $contextId,
                );

                if ($winner instanceof Identifier) {
                    return $winner;
                }

                throw new DuplicateIdentity;
            }

            $tx->recordEvent(new AccessGrantIssued(
                $id,
                $subjectUserId,
                $capability,
                $resourceType,
                $resourceId,
                $contextType,
                $contextId,
                $reasonCode,
                $now,
            ));
            $this->audit->append(
                $tx,
                'access.grant_issued',
                'contextual_access_grant',
                $id,
                ['reason_code' => $reasonCode, 'capability' => $capability],
                $initiator->userId,
                'user',
            );

            return $id;
        });
    }
}
