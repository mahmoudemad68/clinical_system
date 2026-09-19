<?php

declare(strict_types=1);

namespace Modules\Verification\Services\Adapters;

use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Support\TrustedDocumentEvidence;

/**
 * Fail-closed scanner evidence issuer. A real secure-file adapter replaces
 * this binding; doctor and admin actors never do.
 */
final class DisabledTrustedDocumentEvidenceIssuer implements TrustedDocumentEvidenceIssuer
{
    public function canIssue(): bool
    {
        return false;
    }

    public function issue(array $observed): TrustedDocumentEvidence
    {
        unset($observed);

        throw new ProviderNotEnabled(
            'Trusted verification document evidence is not enabled until the secure-file scanner pipeline is wired.',
        );
    }
}
