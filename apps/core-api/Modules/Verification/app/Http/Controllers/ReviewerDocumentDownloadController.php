<?php

declare(strict_types=1);

namespace Modules\Verification\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Support\ReviewerDocumentStreamResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bearer-style reviewer download. Capability is the application signature,
 * not an admin session. Locators are never accepted from the request.
 */
final class ReviewerDocumentDownloadController
{
    public function show(
        Request $request,
        string $caseId,
        string $documentId,
        VerificationDocumentService $handler,
    ): StreamedResponse {
        return ReviewerDocumentStreamResponse::from($handler->openReviewerDownload(
            $caseId,
            $documentId,
            (string) $request->query('expires', ''),
            (string) $request->query('signature', ''),
        ));
    }
}
