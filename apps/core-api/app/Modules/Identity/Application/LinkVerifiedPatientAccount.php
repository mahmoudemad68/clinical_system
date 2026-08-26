<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Auth\Domain\Contracts\AuthTelemetry;
use App\Modules\Identity\Domain\Contracts\PatientIdentityRegistry;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Domain\Exceptions\FeatureUnavailable;

/**
 * Existing-profile claim. Flag-gated off until product/privacy/security/support
 * enablement (ADR 0011). The patient registry is a stub in Phase 01, so this
 * never confirms candidate existence and never attaches a profile.
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
        $this->registry->findClaimCandidate($this->protector->nationalIdHmac($parsed));

        $this->telemetry->claim(['result' => 'manual_review', 'assurance_level' => 'aal1']);

        return ['status' => 'manual_review_required'];
    }
}
