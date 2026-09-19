<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Validation\ValidationException;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Support\TrustedDocumentEvidence;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Test-only scanner evidence issuer. Not bound in production.
 */
final class TestingTrustedDocumentEvidenceIssuer implements TrustedDocumentEvidenceIssuer
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
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new InvalidValueObject('Document digest is not a SHA-256 hex digest.');
        }

        $mime = (string) ($observed['detected_mime'] ?? '');
        if (! $this->policy->isAllowedMime($mime)) {
            throw new InvalidValueObject('Detected MIME type is not allowed.');
        }

        $size = (int) ($observed['size_bytes'] ?? 0);
        if ($size < 1 || $size > $this->policy->maxDocumentBytes()) {
            throw new InvalidValueObject('Document size is outside the allowed bound.');
        }

        try {
            $scan = VerificationDocumentScanStatus::from((string) ($observed['scan_status'] ?? ''));
            $status = VerificationDocumentStatus::from((string) ($observed['status'] ?? ''));
        } catch (\ValueError) {
            throw new InvalidValueObject('Document status is not allowed.');
        }

        if ($status === VerificationDocumentStatus::Available && $scan !== VerificationDocumentScanStatus::Clean) {
            throw ValidationException::withMessages([
                'status' => 'A document cannot become available without a clean scan.',
            ]);
        }

        return TrustedDocumentEvidence::hydrateFromIssuer($this, [
            'case_id' => (string) $observed['case_id'],
            'requirement_code' => (string) $observed['requirement_code'],
            'object_id' => (string) $observed['object_id'],
            'sha256' => $sha,
            'detected_mime' => $mime,
            'size_bytes' => $size,
            'scan_status' => $scan->value,
            'status' => $status->value,
        ]);
    }
}
