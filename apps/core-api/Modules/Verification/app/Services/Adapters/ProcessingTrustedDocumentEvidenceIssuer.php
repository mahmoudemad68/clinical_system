<?php

declare(strict_types=1);

namespace Modules\Verification\Services\Adapters;

use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Support\TrustedDocumentEvidence;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Trusted processing-path issuer. Bound as a concrete class, never as the
 * default TrustedDocumentEvidenceIssuer. HTTP and ActorContext cannot reach it.
 */
final class ProcessingTrustedDocumentEvidenceIssuer implements TrustedDocumentEvidenceIssuer
{
    public function __construct(
        private readonly VerificationPolicy $policy,
    ) {}

    public function canIssue(): bool
    {
        return true;
    }

    public function issue(array $observed): TrustedDocumentEvidence
    {
        $sha = (string) ($observed['sha256'] ?? '');
        $mime = (string) ($observed['detected_mime'] ?? '');
        $size = (int) ($observed['size_bytes'] ?? 0);

        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new InvalidValueObject('Document digest is not a SHA-256 hex digest.');
        }
        if (! $this->policy->isAllowedMime($mime)) {
            throw new InvalidValueObject('Detected MIME type is not allowed.');
        }
        if ($size < 1 || $size > $this->policy->maxDocumentBytes()) {
            throw new InvalidValueObject('Document size is outside the allowed bound.');
        }

        return TrustedDocumentEvidence::hydrateFromIssuer($this, [
            'case_id' => (string) $observed['case_id'],
            'requirement_code' => (string) $observed['requirement_code'],
            'object_id' => (string) $observed['object_id'],
            'sha256' => $sha,
            'detected_mime' => $mime,
            'size_bytes' => $size,
            'scan_status' => (string) $observed['scan_status'],
            'status' => (string) $observed['status'],
            'upload_intent_id' => isset($observed['upload_intent_id']) ? (string) $observed['upload_intent_id'] : null,
        ]);
    }
}
