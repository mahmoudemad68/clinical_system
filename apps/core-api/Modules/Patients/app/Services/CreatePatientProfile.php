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
use Modules\Patients\Events\PatientProfileCreated;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\OnboardingOutcome;
use Modules\Patients\Support\PatientProfileProjector;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Patients\Support\PatientProfileRowFactory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Support\Identifier;

/**
 * Authenticated patient onboarding: create or route to the Phase 01 claim
 * boundary. Never confirms whether a National ID already exists.
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
        private readonly PatientProfileProjector $projector,
        private readonly PatientProfileRowFactory $rows,
        private readonly UserDirectory $identities,
        private readonly LinkVerifiedPatientAccount $claim,
        private readonly Authorize $authorize,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, array $input): OnboardingOutcome
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
        $this->assertMatchesBoundIdentity($actor->userId, $hmacs);

        $already = $this->store->findByUserId($actor->userId, false);
        if ($already instanceof PatientProfileRecord) {
            return new OnboardingOutcome(
                OnboardingOutcome::PROFILE_READY,
                $this->projector->project($already),
                false,
            );
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $input, $nationalId, $hmacs): OnboardingOutcome {
            $this->store->lockLookupIndex($this->protector->nationalIdHmac($nationalId));

            $existing = $this->store->findAuthoritativeByHmacs($hmacs, true);
            if ($existing instanceof PatientProfileRecord) {
                return $this->existingPath($tx, $actor, $existing, (string) $input['national_id']);
            }

            try {
                return $this->createLinked($tx, $actor, $input, $nationalId);
            } catch (DuplicateIdentity) {
                $retry = $this->store->findByUserId($actor->userId, true)
                    ?? $this->store->findAuthoritativeByHmacs($hmacs, true);

                if ($retry instanceof PatientProfileRecord && $retry->userId?->equals($actor->userId) === true) {
                    return new OnboardingOutcome(OnboardingOutcome::PROFILE_READY, $this->projector->project($retry), false);
                }

                throw new FeatureUnavailable;
            }
        });
    }

    /**
     * @param  list<string>  $hmacs
     */
    private function assertMatchesBoundIdentity(Identifier $userId, array $hmacs): void
    {
        $stored = $this->identities->nationalIdLookupHmac($userId);
        if ($stored === null) {
            return;
        }

        foreach ($hmacs as $hmac) {
            if (hash_equals($stored, $hmac)) {
                return;
            }
        }

        throw new FeatureUnavailable;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createLinked(
        TransactionContext $tx,
        ActorContext $actor,
        array $input,
        NationalId $nationalId,
    ): OnboardingOutcome {
        $id = $this->ids->next();
        $now = $this->clock->now();
        $this->store->insert($this->rows->attributes(
            $id,
            $actor->userId,
            $nationalId,
            $input,
            'user',
            $actor->userId,
            $now,
        ));

        $this->audit->append(
            $tx,
            'patient.profile_created',
            'patient_profile',
            $id,
            ['reason_code' => 'self_onboarding', 'source_type' => PatientSourceType::SelfOnboarding->value],
            $actor->userId,
            'user',
        );
        $this->audit->append(
            $tx,
            'patient.account_linked',
            'patient_profile',
            $id,
            [
                'reason_code' => 'self_onboarding',
                'assurance_level' => AssuranceLevel::Ial1SelfAsserted->value,
            ],
            $actor->userId,
            'user',
        );

        $tx->recordEvent(new PatientProfileCreated(
            $id,
            $actor->userId,
            PatientSourceType::SelfOnboarding->value,
            $now,
        ));
        $tx->recordEvent(new PatientAccountLinked(
            $id,
            $actor->userId,
            AssuranceLevel::Ial1SelfAsserted->value,
            $now,
        ));

        $created = $this->store->findById($id, false);
        assert($created instanceof PatientProfileRecord);

        return new OnboardingOutcome(OnboardingOutcome::PROFILE_READY, $this->projector->project($created), true);
    }

    private function existingPath(
        TransactionContext $tx,
        ActorContext $actor,
        PatientProfileRecord $existing,
        string $nationalIdInput,
    ): OnboardingOutcome {
        if ($existing->userId?->equals($actor->userId) === true) {
            return new OnboardingOutcome(OnboardingOutcome::PROFILE_READY, $this->projector->project($existing), false);
        }

        if ($existing->userId === null && $existing->status->isClaimEligible()) {
            $this->audit->append(
                $tx,
                'patient.profile_claim_attempted',
                'patient_profile',
                $existing->id,
                ['reason_code' => 'existing_unlinked'],
                $actor->userId,
                'user',
            );

            $this->claim->handle($actor, $nationalIdInput);

            return new OnboardingOutcome(OnboardingOutcome::MANUAL_REVIEW_REQUIRED, null, false);
        }

        throw new FeatureUnavailable;
    }
}
