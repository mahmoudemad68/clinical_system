<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Features;

/**
 * Server-owned feature flag names.
 *
 * V1 exclusions are always false in the resolver. Diagnostics is env-gated and
 * still fail-closed outside local/development/testing.
 */
final class PlatformFeatures
{
    public const DIAGNOSTICS_SLICE = 'diagnostics-slice';

    public const AUTH_REGISTRATION = 'auth-registration';

    public const IDENTITY_PROFILE_CLAIM = 'identity-profile-claim';

    public const AUTH_RECOVERY = 'auth-recovery';

    /** @var list<string> */
    public const V1_EXCLUSIONS = [
        'online-payments',
        'emergency-chat',
        'drug-alternatives',
        'branch-transfers',
        'patient-adherence',
        'medical-imaging-ai',
        'supplier-api-integration',
        'multi-country',
    ];

    /**
     * Canonical enablement check. V1 exclusions are false even if env or a
     * stored Pennant value claims otherwise (phase file non-goals).
     */
    public static function enabled(string $name): bool
    {
        if (in_array($name, self::V1_EXCLUSIONS, true)) {
            return false;
        }

        if ($name === self::DIAGNOSTICS_SLICE) {
            return (bool) config('platform.features.diagnostics_slice', false);
        }

        if (in_array($name, [self::AUTH_REGISTRATION, self::IDENTITY_PROFILE_CLAIM, self::AUTH_RECOVERY], true)) {
            if ((string) config('app.env') === 'production') {
                return false;
            }

            return match ($name) {
                self::AUTH_REGISTRATION => (bool) config('identity.registration_enabled', false),
                self::IDENTITY_PROFILE_CLAIM => (bool) config('identity.profile_claim_enabled', false),
                default => (bool) config('identity.recovery_enabled', false),
            };
        }

        return false;
    }
}
