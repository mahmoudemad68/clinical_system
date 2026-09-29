<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\NationalId;
use Modules\Patients\Enums\PatientSourceType;
use Modules\Patients\Events\PatientAccountLinked;
use Modules\Patients\Events\PatientClaimLockoutIssued;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\OnboardingOutcome;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Support\Identifier;

/**
 * Authenticated patient onboarding: create or route to the Phase 01 claim
 * boundary. Collision and review paths share one generic pending outcome.
 *
 * Listed in ApprovedCoordinators: Patients writes plus Identity claim lookup
 * and Audit happen in one transaction.
 */
final class CreatePatientProfile
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPatientProfileStore $store,
        private readonly NationalIdProtector $protector,
        private readonly CommitNewPatientProfile $commit,
        private readonly UserDirectory $identities,
        private readonly LinkVerifiedPatientAccount $claim,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly RecordInboxNotification $inbox,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, array $input, Identifier $requestId, ?string $ipPrefix = null): OnboardingOutcome
    {
        if ($actor->accountType !== AccountType::Patient || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::PATIENTS_ONBOARDING);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $nationalId = $this->protector->nationalId((string) $input['national_id']);
        $hmacs = $this->protector->nationalIdLookupHmacs($nationalId);

        $already = $this->store->findByUserId($actor->userId, false);
        if ($already instanceof PatientProfileRecord) {
            return $this->ready($already, false);
        }

        $credential = isset($input['claim_credential']) && is_string($input['claim_credential'])
            ? $input['claim_credential']
            : null;

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $input, $nationalId, $hmacs, $requestId, $credential, $ipPrefix): OnboardingOutcome {
            if (! $this->matchesBoundIdentity($actor->userId, $hmacs)) {
                return $this->manualReview($tx, $actor);
            }

            $this->store->lockLookupIndex($this->protector->nationalIdHmac($nationalId));

            $existing = $this->store->findAuthoritativeByHmacs($hmacs, true);
            if ($existing instanceof PatientProfileRecord) {
                return $this->existingPath($tx, $actor, $existing, (string) $input['national_id'], $credential, $ipPrefix);
            }

            try {
                return $this->createLinked($tx, $actor, $input, $nationalId, $requestId);
            } catch (DuplicateIdentity) {
                $retry = $this->store->findByUserId($actor->userId, true)
                    ?? $this->store->findAuthoritativeByHmacs($hmacs, true);

                if ($retry instanceof PatientProfileRecord) {
                    return $this->existingPath($tx, $actor, $retry, (string) $input['national_id'], $credential, $ipPrefix);
                }

                return $this->manualReview($tx, $actor);
            }
        });
    }

    /**
     * @param  list<string>  $hmacs
     */
    private function matchesBoundIdentity(Identifier $userId, array $hmacs): bool
    {
        $stored = $this->identities->nationalIdLookupHmac($userId);
        if ($stored === null) {
            return true;
        }

        foreach ($hmacs as $hmac) {
            if (hash_equals($stored, $hmac)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createLinked(
        TransactionContext $tx,
        ActorContext $actor,
        array $input,
        NationalId $nationalId,
        Identifier $requestId,
    ): OnboardingOutcome {
        $created = $this->commit->insert(
            $tx,
            $actor->userId,
            $nationalId,
            $input,
            'user',
            $actor->userId,
            PatientSourceType::SelfOnboarding,
            $requestId,
            'self_onboarding',
        );

        $this->audit->append(
            $tx,
            'patient.account_linked',
            'patient_profile',
            $created->id,
            [
                'reason_code' => 'self_onboarding',
                'assurance_level' => AssuranceLevel::Ial1SelfAsserted->value,
            ],
            $actor->userId,
            'user',
        );
        $tx->recordEvent(new PatientAccountLinked(
            $created->id,
            $actor->userId,
            AssuranceLevel::Ial1SelfAsserted->value,
            $created->updatedAt,
        ));

        return $this->ready($created, true);
    }

    private function existingPath(
        TransactionContext $tx,
        ActorContext $actor,
        PatientProfileRecord $existing,
        string $nationalIdInput,
        ?string $claimCredential,
        ?string $ipPrefix,
    ): OnboardingOutcome {
        if ($existing->userId?->equals($actor->userId) === true) {
            return $this->ready($existing, false);
        }

        if ($existing->userId === null && $existing->status->isClaimEligible()) {
            try {
                $outcome = $this->claim->handle($actor, $nationalIdInput, $claimCredential, $ipPrefix);
                if ($outcome->lockoutIssued) {
                    $this->notifyLockout($tx, $actor);
                }
                if ($outcome->isLinked() && $outcome->patientId instanceof Identifier) {
                    $attached = $this->store->findById($outcome->patientId, true);
                    if ($attached instanceof PatientProfileRecord && $attached->userId?->equals($actor->userId) === true) {
                        return $this->claimed($tx, $actor, $attached);
                    }
                }
            } catch (FeatureUnavailable) {
                // Flag off: the ceremony did not attach; the client still sees pending.
            }
        }

        $owned = $this->store->findByUserId($actor->userId, true);
        if ($owned instanceof PatientProfileRecord) {
            return $this->ready($owned, false);
        }

        $refreshed = $this->store->findById($existing->id, true);
        if ($refreshed instanceof PatientProfileRecord && $refreshed->userId?->equals($actor->userId) === true) {
            return $this->ready($refreshed, false);
        }

        return $this->manualReview($tx, $actor);
    }

    private function claimed(TransactionContext $tx, ActorContext $actor, PatientProfileRecord $attached): OnboardingOutcome
    {
        $this->audit->append(
            $tx,
            'patient.account_linked',
            'patient_profile',
            $attached->id,
            [
                'reason_code' => 'profile_claim',
                'assurance_level' => AssuranceLevel::Ial2VerifiedLink->value,
            ],
            $actor->userId,
            'user',
        );
        $tx->recordEvent(new PatientAccountLinked(
            $attached->id,
            $actor->userId,
            AssuranceLevel::Ial2VerifiedLink->value,
            $attached->updatedAt,
        ));
        $this->inbox->record('user', $actor->userId->value, 'patient.profile_bound', [
            'reason_code' => 'successful_profile_bind',
        ]);

        return $this->ready($attached, false);
    }

    private function notifyLockout(TransactionContext $tx, ActorContext $actor): void
    {
        $this->inbox->record('user', $actor->userId->value, 'patient.claim_lockout', [
            'reason_code' => 'failed_proof_lockout',
        ]);
        $tx->recordEvent(new PatientClaimLockoutIssued($actor->userId, $this->clock->now()));
    }

    private function manualReview(TransactionContext $tx, ActorContext $actor): OnboardingOutcome
    {
        $this->audit->append(
            $tx,
            'patient.onboarding_review_required',
            'user',
            $actor->userId,
            ['reason_code' => 'manual_review'],
            $actor->userId,
            'user',
        );

        return new OnboardingOutcome(OnboardingOutcome::MANUAL_REVIEW_REQUIRED, null, null, false);
    }

    private function ready(PatientProfileRecord $row, bool $created): OnboardingOutcome
    {
        return new OnboardingOutcome(
            OnboardingOutcome::PROFILE_READY,
            $row->id->value,
            $row->version,
            $created,
        );
    }
}
