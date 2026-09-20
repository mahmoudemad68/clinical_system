<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a reviewer download in bounded chunks with safe attachment headers.
 * Never buffers the object as one PHP string and never redirects to object storage.
 */
final class ReviewerDocumentStreamResponse
{
    public static function from(ReviewerDocumentStream $download): StreamedResponse
    {
        $stream = $download->stream;
        $maxBytes = $download->maxBytes;
        $chunkBytes = $download->chunkBytes;

        return new StreamedResponse(static function () use ($stream, $maxBytes, $chunkBytes): void {
            try {
                $sent = 0;
                while (is_resource($stream) && ! feof($stream) && $sent < $maxBytes) {
                    $read = fread($stream, min($chunkBytes, $maxBytes - $sent));
                    if ($read === false || $read === '') {
                        break;
                    }
                    echo $read;
                    $sent += strlen($read);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $download->detectedMime,
            'Content-Disposition' => 'attachment; filename="'.$download->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
