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

        return false;
    }
}
