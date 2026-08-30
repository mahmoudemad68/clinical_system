<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use DateTimeImmutable;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Enums\RecoveryNoticeKind;
use Modules\Auth\Events\CredentialVersionChanged;
use Modules\Auth\Events\RecoveryOldChannelNoticeRequested;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Support\Identifier;

final class ApplyRecoveryService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly Authorize $authorizer,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly RecordInboxNotification $inbox,
        private readonly RecordPrivilegedFailure $privilegedFailures,
    ) {}

    public function handle(?ActorContext $operator, Identifier $requestId): string
    {
        if (! PlatformFeatures::enabled(PlatformFeatures::AUTH_RECOVERY)) {
            throw new FeatureUnavailable;
        }

        if ($operator !== null) {
            $decision = $this->authorizer->decide(
                $operator,
                Capabilities::RECOVERY_APPLY,
                'recovery_request',
                $requestId,
            );
            if (! $decision->allowed) {
                $this->privilegedFailures->authorizationDenied(
                    $operator->userId,
                    $operator->accountType->value,
                    $operator->assuranceLevel->value,
                    Capabilities::RECOVERY_APPLY,
                    $decision->reasonCode,
                    $requestId,
                    'recovery_request',
                );
                throw new AuthorizationDenied;
            }
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($operator, $requestId): string {
            $row = $this->auth->lockRecoveryRequest($requestId);
            $now = $this->clock->now();

            if ($row === null || $row->applied_at !== null) {
                throw new InvalidValueObject('The recovery request cannot be applied.');
            }

            $status = (string) $row->status;
            if ($status === 'manual_review') {
                if ($operator === null) {
                    throw new AuthorizationDenied;
                }
            } elseif ($status === 'cooling_off') {
                if ($row->cooling_off_until === null || new DateTimeImmutable((string) $row->cooling_off_until) > $now) {
                    throw new InvalidValueObject('The recovery request cannot be applied.');
                }
            } else {
                throw new InvalidValueObject('The recovery request cannot be applied.');
            }

            $userId = Identifier::fromTrusted((string) $row->user_id);
            $user = $this->identities->lockById($userId);
            if ($user === null) {
                throw new InvalidValueObject('The recovery request cannot be applied.');
            }

            $version = $user->credentialVersion + 1;
            $this->identities->replacePassword($userId, (string) $row->new_password_hash, $version, $now);
            $this->auth->markRecoveryApplied($requestId, $now);
            $this->auth->revokeAllSessions($userId, 'recovery', $now);
            $this->auth->revokeAllDevices($userId, 'recovery', $now);
            $tx->recordEvent(new CredentialVersionChanged($userId, $version, 'recovery', $now));
            $this->audit->append($tx, 'auth.recovery_completed', 'user', $userId, ['reason_code' => 'recovery_applied'], $operator?->userId, $operator === null ? 'system' : 'user');
            $this->inbox->record('user', $userId->value, 'auth.recovery_applied', [
                'recovery_request_id' => $requestId->value,
                'status' => 'applied',
            ]);
            $tx->recordEvent(new RecoveryOldChannelNoticeRequested(
                $requestId,
                RecoveryNoticeKind::Applied,
                $user->language->value,
                $now,
            ));

            return 'applied';
        });
    }
}
