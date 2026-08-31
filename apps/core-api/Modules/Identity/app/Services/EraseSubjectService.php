<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Events\CredentialVersionChanged;
use Modules\Auth\Services\RecordSessionRevokedEvents;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Events\StatusChanged;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\Phase01SubjectHoldings;
use Modules\Identity\Support\SubjectErasureReport;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Services\Privacy\DiscardSubjectTransientCopies;
use Modules\Platform\Support\Identifier;

/**
 * Phase-01 technical subject erasure. Disable/suspend/revoke is not erasure.
 *
 * Listed in ApprovedCoordinators: Identity, Auth, Access, and Platform
 * transients must change in one transaction. Audit remains append-only.
 */
final class EraseSubjectService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly GrantStore $grants,
        private readonly DiscardSubjectTransientCopies $transients,
        private readonly AuthenticationRateLimiter $rates,
        private readonly PasswordHasher $passwords,
        private readonly RandomBytes $random,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly Authorize $authorizer,
        private readonly RecordPrivilegedFailure $privilegedFailures,
        private readonly RecordSessionRevokedEvents $sessionRevoked,
    ) {}

    public function handle(ActorContext $initiator, Identifier $userId, string $reasonCode): SubjectErasureReport
    {
        $decision = $this->authorizer->decide($initiator, Capabilities::IDENTITY_ERASE, 'user', $userId);
        if (! $decision->allowed) {
            $this->privilegedFailures->authorizationDenied(
                $initiator->userId,
                $initiator->accountType->value,
                $initiator->assuranceLevel->value,
                Capabilities::IDENTITY_ERASE,
                $decision->reasonCode,
                $userId,
                'user',
            );
            throw new AuthorizationDenied;
        }

        $plan = Phase01SubjectHoldings::plan();

        $report = $this->transactions->run(function (TransactionContext $tx) use ($initiator, $userId, $reasonCode, $plan): SubjectErasureReport {
            $user = $this->identities->lockById($userId);
            if ($user === null) {
                throw new AuthorizationDenied;
            }

            if ($user->status === AccountStatus::Closed && $user->name === 'erased') {
                return new SubjectErasureReport($userId, true, $plan, []);
            }

            $hmac = $this->identities->phoneLookupHmac($userId) ?? '';
            $identifiers = $this->auth->subjectAuthIdentifiers($userId, $hmac);
            $this->rates->forgetSubject(
                $hmac,
                $identifiers['refresh_family_ids'],
                $identifiers['mfa_challenge_ids'],
                $identifiers['otp_ids'],
            );

            $transientCounts = $this->transients->discard($userId->value, $identifiers['session_ids']);
            $grantCount = $this->grants->eraseSubjectGrants($userId);

            $now = $this->clock->now();
            $affectedSessions = $this->auth->revokeAllSessions($userId, $reasonCode, $now);
            $this->auth->revokeAllDevices($userId, $reasonCode, $now);
            $authCounts = $this->auth->eraseSubjectAuthState($userId, $hmac, $now);

            $nationalIds = $this->identities->deleteNationalIds($userId);
            $profileLinks = $this->identities->deleteProfileLinks($userId);

            $version = $user->credentialVersion + 1;
            $this->identities->tombstoneIdentity(
                $userId,
                $this->random->next(32),
                $this->random->next(32),
                $this->passwords->hash(bin2hex($this->random->next(32))),
                $version,
                $now,
            );

            $this->sessionRevoked->onto($tx, $userId, $affectedSessions, $reasonCode, $now);
            $tx->recordEvent(new CredentialVersionChanged($userId, $version, $reasonCode, $now));
            if ($user->status !== AccountStatus::Closed) {
                $tx->recordEvent(new StatusChanged(
                    $userId,
                    $user->status->value,
                    AccountStatus::Closed->value,
                    $reasonCode,
                    $now,
                ));
            }

            $this->audit->append(
                $tx,
                'identity.subject_erased',
                'user',
                $userId,
                ['reason_code' => $reasonCode],
                $initiator->userId,
                'user',
            );

            return new SubjectErasureReport($userId, false, $plan, [
                'notifications' => $transientCounts['notifications'],
                'laravel_sessions' => $transientCounts['laravel_sessions'],
                'pending_or_failed_outbox' => $transientCounts['pending_or_failed_outbox'],
                'contextual_access_grants' => $grantCount,
                'identity_national_ids' => $nationalIds,
                'identity_profile_links' => $profileLinks,
                'user_devices' => $authCounts['user_devices'],
                'auth_sessions' => $authCounts['auth_sessions'],
                'auth_refresh_consumptions' => $authCounts['auth_refresh_consumptions'],
                'otp_requests' => $authCounts['otp_requests'],
                'mfa_factors' => $authCounts['mfa_factors'],
                'mfa_recovery_codes' => $authCounts['mfa_recovery_codes'],
                'mfa_challenges' => $authCounts['mfa_challenges'],
                'recovery_requests' => $authCounts['recovery_requests'],
            ]);
        });

        assert($report instanceof SubjectErasureReport);

        return $report;
    }
}
