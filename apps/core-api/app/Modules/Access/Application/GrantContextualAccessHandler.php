<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\Contracts\GrantContextualAccess;
use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Access\Domain\Events\AccessGrantIssued;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\Exceptions\DuplicateIdentity;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class GrantContextualAccessHandler implements GrantContextualAccess
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
