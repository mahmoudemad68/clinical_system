<?php

declare(strict_types=1);

namespace Modules\Verification\Contracts;

use Modules\Verification\Support\TrustedDocumentEvidence;

/**
 * Replaceable secure-file/scanner boundary. Production is fail-closed until a
 * real scanner adapter is wired. ActorContext is never an issuer.
 */
interface TrustedDocumentEvidenceIssuer
{
    /**
     * Production Disabled issuer is false. A wired scanner adapter is true.
     */
    public function canIssue(): bool;

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
    public function issue(array $observed): TrustedDocumentEvidence;
}
