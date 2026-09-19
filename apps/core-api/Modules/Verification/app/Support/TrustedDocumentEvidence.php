<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;

/**
 * Scanner-issued document evidence. Callers cannot construct this from an
 * ActorContext; only a TrustedDocumentEvidenceIssuer implementation may.
 */
final readonly class TrustedDocumentEvidence
{
    private function __construct(
        public Identifier $caseId,
        public string $requirementCode,
        public Identifier $objectId,
        public string $sha256,
        public string $detectedMime,
        public int $sizeBytes,
        public VerificationDocumentScanStatus $scanStatus,
        public VerificationDocumentStatus $status,
    ) {}

    /**
     * @param  array{
     *     case_id: string,
     *     requirement_code: string,
     *     object_id: string,
     *     sha256: string,
     *     detected_mime: string,
     *     size_bytes: int,
     *     scan_status: string,
     *     status: string
     * }  $observed
     */
    public static function hydrateFromIssuer(TrustedDocumentEvidenceIssuer $issuer, array $observed): self
    {
        if (! $issuer->canIssue()) {
            throw new ProviderNotEnabled(
                'Trusted verification document evidence is not enabled until the secure-file scanner pipeline is wired.',
            );
        }

        return new self(
            Identifier::fromString($observed['case_id']),
            $observed['requirement_code'],
            Identifier::fromString($observed['object_id']),
            $observed['sha256'],
            $observed['detected_mime'],
            $observed['size_bytes'],
            VerificationDocumentScanStatus::from($observed['scan_status']),
            VerificationDocumentStatus::from($observed['status']),
        );
    }
}
