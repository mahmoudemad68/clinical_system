<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use DateTimeImmutable;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\AuthTelemetry;
use Modules\Auth\Enums\OtpPurpose;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\ClaimCredential;
use Modules\Identity\Support\ProfileClaimOutcome;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Support\Identifier;

/**
 * Existing-profile hybrid claim ceremony (frozen Policy v1).
 *
 * Canonical enablement is PlatformFeatures. Production remains hard-off.
 * Failure paths never disclose candidate, credential, or lock state.
 * Clients still see only manual_review_required or hidden NOT_FOUND.
 */
final class LinkVerifiedPatientAccount
{
    public function __construct(
        private readonly NationalIdProtector $protector,
        private readonly PatientIdentityRegistry $registry,
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly AuthenticationRateLimiter $rates,
        private readonly AuthTelemetry $telemetry,
        private readonly HmacHasher $hmac,
        private readonly Clock $clock,
        private readonly IdentityGenerator $ids,
    ) {}

    public function handle(
        ActorContext $actor,
        string $nationalId,
        ?string $claimCredential = null,
        ?string $ipPrefix = null,
    ): ProfileClaimOutcome {
        if (! PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)) {
            throw new FeatureUnavailable;
        }

        if ($actor->accountType !== AccountType::Patient || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $parsed = $this->protector->nationalId($nationalId);
        $hmacs = $this->protector->nationalIdLookupHmacs($parsed);
        $canonicalHmac = $this->protector->nationalIdHmac($parsed);
        $now = $this->clock->now();

        if (! $this->rates->consumeClaimCeremonyStart($actor->userId->value, bin2hex($canonicalHmac), $ipPrefix ?? '0.0.0.0')) {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        $boundHmac = $this->identities->nationalIdLookupHmac($actor->userId);
        if ($boundHmac === null || $boundHmac === '') {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        $boundMatches = false;
        foreach ($hmacs as $hmac) {
            if (hash_equals($boundHmac, $hmac)) {
                $boundMatches = true;
                break;
            }
        }
        if (! $boundMatches) {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        $matches = $this->registry->countAuthoritativeMatches($hmacs);
        if ($matches > 1) {
            $this->telemetry->claimConflict(['reason_code' => 'duplicate_match']);

            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        $candidateId = null;
        foreach ($hmacs as $hmac) {
            $found = $this->registry->findClaimCandidate($hmac);
            if ($found instanceof Identifier) {
                $candidateId = $found;
                break;
            }
        }
        if (! $candidateId instanceof Identifier) {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        if ($this->registry->isClaimLocked($actor->userId, $canonicalHmac, $now)) {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        if (! $this->freshConsumedProfileClaimOtp($actor, $now)) {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        $canonicalCredential = is_string($claimCredential) ? ClaimCredential::canonicalize($claimCredential) : null;
        if ($canonicalCredential === null) {
            return $this->failedCredential($actor, $canonicalHmac, $now);
        }

        $credentialHash = $this->hmac->digest(ClaimCredential::HMAC_PURPOSE, $canonicalCredential);
        if (! $this->registry->consumeUnexpiredCredential($candidateId, $credentialHash, $now)) {
            return $this->failedCredential($actor, $canonicalHmac, $now);
        }

        try {
            $this->registry->attachAccount($candidateId, $actor->userId, $this->ids->next());
        } catch (DuplicateIdentity) {
            return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW);
        }

        $this->telemetry->claim([
            'result' => 'linked',
            'assurance_level' => AssuranceLevel::Ial2VerifiedLink->value,
        ]);

        return ProfileClaimOutcome::linked($candidateId);
    }

    private function pending(string $result, bool $lockoutIssued = false): ProfileClaimOutcome
    {
        $this->telemetry->claim([
            'result' => $result,
            'assurance_level' => AssuranceLevel::Ial2ProofPending->value,
        ]);

        return ProfileClaimOutcome::manualReview(AssuranceLevel::Ial2ProofPending->value, $lockoutIssued);
    }

    private function failedCredential(ActorContext $actor, string $canonicalHmac, DateTimeImmutable $now): ProfileClaimOutcome
    {
        $abuse = $this->registry->recordCredentialFailure($actor->userId, $canonicalHmac, $now);

        return $this->pending(ProfileClaimOutcome::MANUAL_REVIEW, $abuse === 'locked');
    }

    private function freshConsumedProfileClaimOtp(ActorContext $actor, DateTimeImmutable $now): bool
    {
        $user = $this->identities->findById($actor->userId);
        if ($user === null || ! $user->phoneVerified) {
            return false;
        }

        $phoneHmac = $this->identities->phoneLookupHmac($actor->userId);
        if ($phoneHmac === null || $phoneHmac === '') {
            return false;
        }

        $row = $this->auth->latestConsumedOtp(OtpPurpose::ProfileClaim->value, [$phoneHmac]);
        if ($row === null || $row->consumed_at === null) {
            return false;
        }

        $consumedAt = new DateTimeImmutable((string) $row->consumed_at);
        $recency = (int) config('identity.profile_claim.otp_recency_minutes', 10);

        return $consumedAt >= $now->modify(sprintf('-%d minutes', $recency));
    }
}
