<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Events\CredentialVersionChanged;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\RecordInboxNotification;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\Exceptions\FeatureUnavailable;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class ApplyRecoveryHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly Authorize $authorizer,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly RecordInboxNotification $inbox,
    ) {}

    public function handle(?ActorContext $operator, Identifier $requestId): string
    {
        if (! PlatformFeatures::enabled(PlatformFeatures::AUTH_RECOVERY)) {
            throw new FeatureUnavailable;
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
                $decision = $this->authorizer->decide($operator, Capabilities::RECOVERY_APPLY, 'recovery_request', $requestId);
                if (! $decision->allowed) {
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

            return 'applied';
        });
    }
}
