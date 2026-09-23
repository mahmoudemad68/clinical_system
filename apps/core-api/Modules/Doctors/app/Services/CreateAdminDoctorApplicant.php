<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Doctors\Enums\DoctorEvidenceSource;
use Modules\Doctors\Enums\DoctorSourceType;
use Modules\Doctors\Events\DoctorProfileCreated;
use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Services\Persistence\PostgresSpecialtyStore;
use Modules\Doctors\Support\AdminCreatedDoctorResult;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Doctors\Support\DoctorProfileRowFactory;
use Modules\Doctors\Support\SpecialtyRecord;
use Modules\Doctors\Support\SyndicateNumber;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Events\AccountRegistered;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Services\ProvisionDoctorApplicantAccount;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\NationalId;
use Modules\Identity\Support\UserAccount;
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
 * Privileged Admin-created doctor applicant. Creates the Identity account and
 * a draft hidden doctor profile. Does not approve, list, or grant clinical
 * capability. Verification owns the case/document/decision path.
 *
 * Listed in ApprovedCoordinators: Doctors writes plus Identity provisioning
 * and Audit happen in one transaction.
 */
final class CreateAdminDoctorApplicant
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresDoctorProfileStore $store,
        private readonly PostgresSpecialtyStore $specialties,
        private readonly NationalIdProtector $protector,
        private readonly HmacHasher $hmac,
        private readonly DoctorProfileRowFactory $rows,
        private readonly UserDirectory $identities,
        private readonly ProvisionDoctorApplicantAccount $accounts,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(ActorContext $actor, array $input): AdminCreatedDoctorResult
    {
        if ($actor->accountType !== AccountType::Admin || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::DOCTORS_ADMIN_CREATE);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $phone = $this->protector->phone((string) $input['phone']);
        $nationalId = $this->protector->nationalId((string) $input['national_id']);
        $evidence = DoctorEvidenceSource::from((string) $input['evidence_source']);
        $nidHmacs = $this->protector->nationalIdLookupHmacs($nationalId);
        $phoneHmacs = $this->protector->phoneLookupHmacs($phone);
        $syndicate = SyndicateNumber::canonical(
            isset($input['syndicate_number']) && is_string($input['syndicate_number'])
                ? $input['syndicate_number']
                : null,
        );
        $syndicateHmacs = $syndicate === null ? [] : $this->hmac->lookupDigests('syndicate_number_lookup', $syndicate);
        $specialtyId = Identifier::fromString((string) $input['specialty_id']);
        $password = (string) $input['password'];

        try {
            return $this->transactions->run(function (TransactionContext $tx) use (
                $actor,
                $input,
                $phone,
                $nationalId,
                $evidence,
                $nidHmacs,
                $phoneHmacs,
                $syndicate,
                $syndicateHmacs,
                $specialtyId,
                $password,
            ): AdminCreatedDoctorResult {
                $specialty = $this->specialties->findActiveById($specialtyId, true);
                if (! $specialty instanceof SpecialtyRecord) {
                    throw new InvalidValueObject('Specialty is not available.');
                }

                $this->store->lockLookupIndex($this->protector->nationalIdHmac($nationalId));
                if ($syndicate !== null) {
                    $this->store->lockLookupIndex($this->hmac->digest('syndicate_number_lookup', $syndicate));
                }

                $byNationalId = $this->store->findByNationalIdHmacs($nidHmacs, true);
                if ($byNationalId instanceof DoctorProfileRecord) {
                    return $this->existingOwned($tx, $actor, $byNationalId, $nidHmacs);
                }

                if ($syndicateHmacs !== []) {
                    $bySyndicate = $this->store->findBySyndicateHmacs($syndicateHmacs, true);
                    if ($bySyndicate instanceof DoctorProfileRecord) {
                        return $this->existingOwned($tx, $actor, $bySyndicate, $nidHmacs);
                    }
                }

                $existingUser = $this->identities->findByPhoneHmacs($phoneHmacs);
                if ($existingUser instanceof UserAccount) {
                    $linked = $this->store->findByUserId($existingUser->id, true);
                    if ($linked instanceof DoctorProfileRecord) {
                        return $this->existingOwned($tx, $actor, $linked, $nidHmacs);
                    }

                    return $this->manualReview($tx, $actor);
                }

                $user = $this->accounts->create(
                    (string) $input['professional_display_name'],
                    $phone,
                    $nationalId,
                    $password,
                    $this->clock->now(),
                );

                return $this->insertProfile(
                    $tx,
                    $actor,
                    $user->id,
                    $input,
                    $nationalId,
                    $specialtyId,
                    $syndicate,
                    $evidence,
                );
            });
        } catch (DuplicateIdentity) {
            $retry = $this->store->findByNationalIdHmacs($nidHmacs, false)
                ?? ($syndicateHmacs === [] ? null : $this->store->findBySyndicateHmacs($syndicateHmacs, false));

            if ($retry instanceof DoctorProfileRecord) {
                return $this->transactions->run(function (TransactionContext $tx) use ($actor, $retry, $nidHmacs): AdminCreatedDoctorResult {
                    return $this->existingOwned($tx, $actor, $retry, $nidHmacs);
                });
            }

            return $this->transactions->run(function (TransactionContext $tx) use ($actor): AdminCreatedDoctorResult {
                return $this->manualReview($tx, $actor);
            });
        }
    }

    /**
     * @param  list<string>  $nidHmacs
     */
    private function existingOwned(
        TransactionContext $tx,
        ActorContext $actor,
        DoctorProfileRecord $existing,
        array $nidHmacs,
    ): AdminCreatedDoctorResult {
        if ($existing->createdByUserId->equals($actor->userId) && $this->matchesNationalId($existing, $nidHmacs)) {
            return AdminCreatedDoctorResult::alreadyExists($existing->id->value, $existing->version);
        }

        return $this->manualReview($tx, $actor);
    }

    /**
     * @param  list<string>  $nidHmacs
     */
    private function matchesNationalId(DoctorProfileRecord $row, array $nidHmacs): bool
    {
        foreach ($nidHmacs as $hmac) {
            if (hash_equals($row->nationalIdLookupHmac, $hmac)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function insertProfile(
        TransactionContext $tx,
        ActorContext $actor,
        Identifier $ownerUserId,
        array $input,
        NationalId $nationalId,
        Identifier $specialtyId,
        ?string $syndicate,
        DoctorEvidenceSource $evidence,
    ): AdminCreatedDoctorResult {
        $id = $this->ids->next();
        $now = $this->clock->now();

        $this->store->insert($this->rows->attributes(
            $id,
            $ownerUserId,
            $nationalId,
            $specialtyId,
            $syndicate,
            $input,
            $now,
            $actor->userId,
            DoctorSourceType::AdminCreated,
        ));

        $this->audit->append(
            $tx,
            'identity.account_registered',
            'user',
            $ownerUserId,
            ['reason_code' => 'admin_created_doctor'],
            $actor->userId,
            'user',
        );
        $this->audit->append(
            $tx,
            'doctor.profile_created',
            'doctor_profile',
            $id,
            [
                'reason_code' => 'admin_created',
                'source_type' => DoctorSourceType::AdminCreated->value,
                'evidence_source' => $evidence->value,
            ],
            $actor->userId,
            'user',
        );
        $tx->recordEvent(new AccountRegistered(
            $ownerUserId,
            'active',
            'ar',
            $now,
        ));
        $tx->recordEvent(new DoctorProfileCreated(
            $id,
            $ownerUserId,
            DoctorSourceType::AdminCreated->value,
            $now,
        ));

        $created = $this->store->findById($id, false);
        assert($created instanceof DoctorProfileRecord);

        return AdminCreatedDoctorResult::created($created->id->value, $created->version);
    }

    private function manualReview(TransactionContext $tx, ActorContext $actor): AdminCreatedDoctorResult
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

        return AdminCreatedDoctorResult::manualReview();
    }
}
