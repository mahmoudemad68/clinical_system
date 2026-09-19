<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Doctors\Enums\DoctorSourceType;
use Modules\Doctors\Events\DoctorProfileCreated;
use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Services\Persistence\PostgresSpecialtyStore;
use Modules\Doctors\Support\DoctorOnboardingOutcome;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Doctors\Support\DoctorProfileRowFactory;
use Modules\Doctors\Support\SpecialtyRecord;
use Modules\Doctors\Support\SyndicateNumber;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\NationalId;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

/**
 * Authenticated doctor self-onboarding. Creates exactly one draft, non-public
 * profile. Collision and review paths share one generic pending outcome.
 *
 * Listed in ApprovedCoordinators: Doctors writes plus Identity lookup and
 * Audit happen in one transaction.
 */
final class RegisterDoctor
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresDoctorProfileStore $store,
        private readonly PostgresSpecialtyStore $specialties,
        private readonly NationalIdProtector $protector,
        private readonly HmacHasher $hmac,
        private readonly DoctorProfileRowFactory $rows,
        private readonly UserDirectory $identities,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, array $input): DoctorOnboardingOutcome
    {
        if ($actor->accountType !== AccountType::Doctor || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::DOCTORS_ONBOARDING);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $nationalId = $this->protector->nationalId((string) $input['national_id']);
        $nidHmacs = $this->protector->nationalIdLookupHmacs($nationalId);
        $syndicate = SyndicateNumber::canonical(
            isset($input['syndicate_number']) && is_string($input['syndicate_number'])
                ? $input['syndicate_number']
                : null,
        );
        $syndicateHmacs = $syndicate === null ? [] : $this->hmac->lookupDigests('syndicate_number_lookup', $syndicate);
        $specialtyId = Identifier::fromString((string) $input['specialty_id']);

        $already = $this->store->findByUserId($actor->userId, false);
        if ($already instanceof DoctorProfileRecord) {
            return $this->ready($already, false);
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $input, $nationalId, $nidHmacs, $syndicate, $syndicateHmacs, $specialtyId): DoctorOnboardingOutcome {
            $specialty = $this->specialties->findActiveById($specialtyId, true);
            if (! $specialty instanceof SpecialtyRecord) {
                throw new InvalidValueObject('Specialty is not available.');
            }

            if (! $this->matchesBoundIdentity($actor->userId, $nidHmacs)) {
                return $this->manualReview($tx, $actor);
            }

            $this->store->lockLookupIndex($this->protector->nationalIdHmac($nationalId));
            if ($syndicate !== null) {
                $this->store->lockLookupIndex($this->hmac->digest('syndicate_number_lookup', $syndicate));
            }

            $byNationalId = $this->store->findByNationalIdHmacs($nidHmacs, true);
            if ($byNationalId instanceof DoctorProfileRecord) {
                return $this->existingPath($tx, $actor, $byNationalId);
            }

            if ($syndicateHmacs !== []) {
                $bySyndicate = $this->store->findBySyndicateHmacs($syndicateHmacs, true);
                if ($bySyndicate instanceof DoctorProfileRecord) {
                    return $this->existingPath($tx, $actor, $bySyndicate);
                }
            }

            try {
                return $this->createDraft($tx, $actor, $input, $nationalId, $specialtyId, $syndicate);
            } catch (DuplicateIdentity) {
                $retry = $this->store->findByUserId($actor->userId, true)
                    ?? $this->store->findByNationalIdHmacs($nidHmacs, true)
                    ?? ($syndicateHmacs === [] ? null : $this->store->findBySyndicateHmacs($syndicateHmacs, true));

                if ($retry instanceof DoctorProfileRecord) {
                    return $this->existingPath($tx, $actor, $retry);
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
    private function createDraft(
        TransactionContext $tx,
        ActorContext $actor,
        array $input,
        NationalId $nationalId,
        Identifier $specialtyId,
        ?string $syndicate,
    ): DoctorOnboardingOutcome {
        $id = $this->ids->next();
        $now = $this->clock->now();

        $this->store->insert($this->rows->attributes(
            $id,
            $actor->userId,
            $nationalId,
            $specialtyId,
            $syndicate,
            $input,
            $now,
        ));

        $this->audit->append(
            $tx,
            'doctor.profile_created',
            'doctor_profile',
            $id,
            ['reason_code' => 'self_onboarding', 'source_type' => DoctorSourceType::SelfOnboarding->value],
            $actor->userId,
            'user',
        );
        $tx->recordEvent(new DoctorProfileCreated(
            $id,
            $actor->userId,
            DoctorSourceType::SelfOnboarding->value,
            $now,
        ));

        $created = $this->store->findById($id, false);
        assert($created instanceof DoctorProfileRecord);

        return $this->ready($created, true);
    }

    private function existingPath(
        TransactionContext $tx,
        ActorContext $actor,
        DoctorProfileRecord $existing,
    ): DoctorOnboardingOutcome {
        if ($existing->userId->equals($actor->userId)) {
            return $this->ready($existing, false);
        }

        return $this->manualReview($tx, $actor);
    }

    private function manualReview(TransactionContext $tx, ActorContext $actor): DoctorOnboardingOutcome
    {
        $this->audit->append(
            $tx,
            'doctor.onboarding_review_required',
            'user',
            $actor->userId,
            ['reason_code' => 'manual_review'],
            $actor->userId,
            'user',
        );

        return new DoctorOnboardingOutcome(DoctorOnboardingOutcome::MANUAL_REVIEW_REQUIRED, null, null, false);
    }

    private function ready(DoctorProfileRecord $row, bool $created): DoctorOnboardingOutcome
    {
        return new DoctorOnboardingOutcome(
            DoctorOnboardingOutcome::PROFILE_READY,
            $row->id->value,
            $row->version,
            $created,
        );
    }
}
