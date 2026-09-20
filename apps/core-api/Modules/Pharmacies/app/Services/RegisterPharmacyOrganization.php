<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\PhoneE164;
use Modules\Pharmacies\Enums\PharmacySourceType;
use Modules\Pharmacies\Events\PharmacyOrganizationCreated;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\LegalRegistration;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyCoordinates;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOnboardingOutcome;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Authenticated pharmacy self-onboarding. Creates one draft organization,
 * its initial branch, and the founding owner membership in one transaction.
 * Collision and review paths share one generic pending outcome.
 *
 * Listed in ApprovedCoordinators: Pharmacies writes plus Audit happen in
 * one transaction.
 */
final class RegisterPharmacyOrganization
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly NationalIdProtector $protector,
        private readonly HmacHasher $hmac,
        private readonly PharmacyOrganizationRowFactory $rows,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, array $input): PharmacyOnboardingOutcome
    {
        if ($actor->accountType !== AccountType::Pharmacy || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::PHARMACIES_ONBOARDING);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $registration = LegalRegistration::canonical((string) $input['legal_registration_identifier']);
        $registrationHmacs = $this->hmac->lookupDigests(
            PharmacyOrganizationRowFactory::HMAC_LEGAL_REGISTRATION,
            $registration,
        );
        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        PharmacyCoordinates::assertEgyptServiceArea($latitude, $longitude);

        $country = strtoupper(trim((string) $input['country_code']));
        if ($country !== 'EG') {
            throw new InvalidValueObject('Country is not available.');
        }

        $phone = $this->protector->phone((string) $input['phone']);

        $already = $this->store->findOwnerMembershipByUserId($actor->userId, false);
        if ($already instanceof PharmacyMembershipRecord) {
            return $this->readyFromMembership($already, false);
        }

        return $this->transactions->run(function (TransactionContext $tx) use (
            $actor,
            $input,
            $registration,
            $registrationHmacs,
            $latitude,
            $longitude,
            $phone,
        ): PharmacyOnboardingOutcome {
            $this->store->lockLookupIndex($this->hmac->digest(
                PharmacyOrganizationRowFactory::HMAC_LEGAL_REGISTRATION,
                $registration,
            ));
            $this->store->lockLookupIndex('owner:'.$actor->userId->value);

            $owned = $this->store->findOwnerMembershipByUserId($actor->userId, true);
            if ($owned instanceof PharmacyMembershipRecord) {
                return $this->readyFromMembership($owned, false);
            }

            $byRegistration = $this->store->findByRegistrationHmacs($registrationHmacs, true);
            if ($byRegistration instanceof PharmacyOrganizationRecord) {
                return $this->existingPath($tx, $actor, $byRegistration);
            }

            try {
                return $this->createDraft($tx, $actor, $input, $registration, $phone, $latitude, $longitude);
            } catch (DuplicateIdentity) {
                $retryMembership = $this->store->findOwnerMembershipByUserId($actor->userId, true);
                if ($retryMembership instanceof PharmacyMembershipRecord) {
                    return $this->readyFromMembership($retryMembership, false);
                }

                $retryOrg = $this->store->findByRegistrationHmacs($registrationHmacs, true);
                if ($retryOrg instanceof PharmacyOrganizationRecord) {
                    return $this->existingPath($tx, $actor, $retryOrg);
                }

                return $this->manualReview($tx, $actor);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createDraft(
        TransactionContext $tx,
        ActorContext $actor,
        array $input,
        string $registration,
        PhoneE164 $phone,
        float $latitude,
        float $longitude,
    ): PharmacyOnboardingOutcome {
        $organizationId = $this->ids->next();
        $branchId = $this->ids->next();
        $membershipId = $this->ids->next();
        $now = $this->clock->now();

        $this->store->insertOrganization($this->rows->organizationAttributes(
            $organizationId,
            $registration,
            $input,
            $now,
        ));
        $this->store->insertBranch(
            $this->rows->branchAttributes($branchId, $organizationId, $phone, $input, $now),
            $longitude,
            $latitude,
        );
        $this->store->insertMembership($this->rows->ownerMembershipAttributes(
            $membershipId,
            $organizationId,
            $actor->userId,
            $now,
        ));

        $this->audit->append(
            $tx,
            'pharmacy.organization_created',
            'pharmacy_organization',
            $organizationId,
            ['reason_code' => 'self_onboarding', 'source_type' => PharmacySourceType::SelfOnboarding->value],
            $actor->userId,
            'user',
        );
        $tx->recordEvent(new PharmacyOrganizationCreated(
            $organizationId,
            $branchId,
            $membershipId,
            $actor->userId,
            PharmacySourceType::SelfOnboarding->value,
            $now,
        ));

        return new PharmacyOnboardingOutcome(
            PharmacyOnboardingOutcome::ORGANIZATION_READY,
            $organizationId->value,
            $branchId->value,
            $membershipId->value,
            1,
            true,
        );
    }

    private function existingPath(
        TransactionContext $tx,
        ActorContext $actor,
        PharmacyOrganizationRecord $existing,
    ): PharmacyOnboardingOutcome {
        $owner = $this->store->findOwnerMembershipByOrganizationId($existing->id, true);
        if ($owner instanceof PharmacyMembershipRecord && $owner->userId->equals($actor->userId)) {
            return $this->readyFromMembership($owner, false);
        }

        return $this->manualReview($tx, $actor);
    }

    private function readyFromMembership(PharmacyMembershipRecord $membership, bool $created): PharmacyOnboardingOutcome
    {
        $organization = $this->store->findOrganizationById($membership->organizationId, false);
        $branch = $this->store->findInitialBranch($membership->organizationId, false);
        if (! $organization instanceof PharmacyOrganizationRecord || ! $branch instanceof PharmacyBranchRecord) {
            throw new AuthorizationDenied;
        }

        return new PharmacyOnboardingOutcome(
            PharmacyOnboardingOutcome::ORGANIZATION_READY,
            $organization->id->value,
            $branch->id->value,
            $membership->id->value,
            $organization->version,
            $created,
        );
    }

    private function manualReview(TransactionContext $tx, ActorContext $actor): PharmacyOnboardingOutcome
    {
        $this->audit->append(
            $tx,
            'pharmacy.onboarding_review_required',
            'user',
            $actor->userId,
            ['reason_code' => 'manual_review'],
            $actor->userId,
            'user',
        );

        return new PharmacyOnboardingOutcome(
            PharmacyOnboardingOutcome::MANUAL_REVIEW_REQUIRED,
            null,
            null,
            null,
            null,
            false,
        );
    }
}
