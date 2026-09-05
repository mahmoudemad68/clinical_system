<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Modules\Auth\Contracts\AuthTelemetry;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Services\Features\PlatformFeatures;

/**
 * Existing-profile claim. Flag-gated off until product/privacy/security/support
 * enablement (ADR 0011). Patients may rebind PatientIdentityRegistry; this
 * still never confirms candidate existence and never attaches a profile.
 */
final class LinkVerifiedPatientAccount
{
    public function __construct(
        private readonly NationalIdProtector $protector,
        private readonly PatientIdentityRegistry $registry,
        private readonly AuthTelemetry $telemetry,
    ) {}

    /**
     * @return array{status: string}
     */
    public function handle(ActorContext $actor, string $nationalId): array
    {
        if (! PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)) {
            throw new FeatureUnavailable;
        }

        if (! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $parsed = $this->protector->nationalId($nationalId);
        foreach ($this->protector->nationalIdLookupHmacs($parsed) as $hmac) {
            $this->registry->findClaimCandidate($hmac);
        }

        $this->telemetry->claim(['result' => 'manual_review', 'assurance_level' => 'aal1']);

        return ['status' => 'manual_review_required'];
    }
}
