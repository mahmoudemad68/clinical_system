<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Verification\Exceptions\ReviewerDocumentStreamAborted;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a reviewer download in bounded chunks with safe attachment headers.
 * Never buffers the object as one PHP string and never redirects to object storage.
 * Emits exactly expectedBytes or aborts so the connection cannot complete as a
 * valid document. Content-Length is the trusted size, not a provider guess.
 */
final class ReviewerDocumentStreamResponse
{
    public static function from(ReviewerDocumentStream $download): StreamedResponse
    {
        $stream = $download->stream;
        $expectedBytes = $download->expectedBytes;
        $chunkBytes = $download->chunkBytes;

        return new StreamedResponse(static function () use ($stream, $expectedBytes, $chunkBytes): void {
            try {
                $sent = 0;
                while (is_resource($stream) && ! feof($stream) && $sent < $expectedBytes) {
                    $read = fread($stream, min($chunkBytes, $expectedBytes - $sent));
                    if ($read === false || $read === '') {
                        throw ReviewerDocumentStreamAborted::incomplete();
                    }
                    echo $read;
                    $sent += strlen($read);
                }

                if ($sent !== $expectedBytes) {
                    throw ReviewerDocumentStreamAborted::incomplete();
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $download->detectedMime,
            'Content-Disposition' => 'attachment; filename="'.$download->filename.'"',
            'Content-Length' => (string) $expectedBytes,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
