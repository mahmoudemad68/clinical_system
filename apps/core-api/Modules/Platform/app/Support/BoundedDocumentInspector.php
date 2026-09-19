<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Exceptions\InvalidValueObject;
use RuntimeException;

/**
 * Bounded streaming inspection. Never loads the whole object as a PHP string.
 */
final class BoundedDocumentInspector
{
    public function __construct(
        private readonly int $chunkBytes = 65_536,
        private readonly int $maxPages = 50,
        private readonly int $maxImageEdge = 8_192,
    ) {}

    /**
     * @param  resource  $stream
     * @param  list<string>  $allowedMimes
     */
    public function inspect($stream, int $maxBytes, string $declaredMime, array $allowedMimes): MediaInspection
    {
        if (! is_resource($stream)) {
            throw new InvalidValueObject('Object stream is not available.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $head = '';
        $tail = '';
        $pdfNeedle = '';
        $png = ['seen_sig' => false, 'ihdr' => false, 'width' => 0, 'height' => 0, 'chunks' => 0, 'buf' => '', 'ended' => false];
        $jpeg = ['sof' => false, 'width' => 0, 'height' => 0, 'eoi' => false];

        while (! feof($stream)) {
            $chunk = fread($stream, $this->chunkBytes);
            if ($chunk === false) {
                throw new RuntimeException('Object stream read failed.');
            }
            if ($chunk === '') {
                break;
            }

            $size += strlen($chunk);
            if ($size > $maxBytes) {
                return $this->reject($size, hash_final($hash), FileMagic::detect($head), 'oversized');
            }

            hash_update($hash, $chunk);

            if ($head === '') {
                $head = substr($chunk, 0, 16);
            } elseif (strlen($head) < 16) {
                $head .= substr($chunk, 0, 16 - strlen($head));
            }

            $tail .= $chunk;
            if (strlen($tail) > 2048) {
                $tail = substr($tail, -2048);
            }

            $detected = FileMagic::detect($head);
            if ($detected === FileMagic::PDF) {
                $pdfNeedle .= $chunk;
                if (strlen($pdfNeedle) > 4096) {
                    $pdfNeedle = substr($pdfNeedle, -4096);
                }
                if ($this->pdfHasActiveContent($chunk) || $this->pdfHasActiveContent($pdfNeedle)) {
                    return $this->reject($size, hash_final($hash), FileMagic::PDF, 'malformed');
                }
            } elseif ($detected === FileMagic::PNG) {
                $png['buf'] .= $chunk;
                $pngReason = $this->consumePng($png);
                if (is_string($pngReason)) {
                    return $this->reject($size, hash_final($hash), FileMagic::PNG, $pngReason);
                }
            } elseif ($detected === FileMagic::JPEG) {
                $jpegReason = $this->consumeJpeg($jpeg, $chunk, $head, $size === strlen($chunk));
                if (is_string($jpegReason)) {
                    return $this->reject($size, hash_final($hash), FileMagic::JPEG, $jpegReason);
                }
            }
        }

        $sha = hash_final($hash);
        if ($size === 0) {
            return $this->reject(0, $sha, null, 'zero_byte');
        }

        $detectedMime = FileMagic::detect($head);
        if ($detectedMime === null) {
            return $this->reject($size, $sha, null, 'unsupported_format');
        }
        if (! in_array($detectedMime, $allowedMimes, true)) {
            return $this->reject($size, $sha, $detectedMime, 'unsupported_format');
        }
        if ($declaredMime !== $detectedMime) {
            return $this->reject($size, $sha, $detectedMime, 'mime_mismatch');
        }

        if ($detectedMime === FileMagic::PDF) {
            if (! str_contains($tail, '%%EOF')) {
                return $this->reject($size, $sha, $detectedMime, 'malformed');
            }
        }

        if ($detectedMime === FileMagic::PNG && ($png['ended'] !== true || $png['ihdr'] !== true)) {
            return $this->reject($size, $sha, $detectedMime, 'malformed');
        }

        if ($detectedMime === FileMagic::JPEG && ($jpeg['sof'] !== true || $jpeg['eoi'] !== true)) {
            return $this->reject($size, $sha, $detectedMime, 'malformed');
        }

        return new MediaInspection(true, $size, $sha, $detectedMime, $sha, null);
    }

    /**
     * @param  resource  $stream
     */
    public function hashAndSize($stream, int $maxBytes): ObservedObject
    {
        if (! is_resource($stream)) {
            throw new InvalidValueObject('Object stream is not available.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $head = '';

        while (! feof($stream)) {
            $chunk = fread($stream, $this->chunkBytes);
            if ($chunk === false) {
                throw new RuntimeException('Object stream read failed.');
            }
            if ($chunk === '') {
                break;
            }
            $size += strlen($chunk);
            if ($size > $maxBytes) {
                throw new InvalidValueObject('Object exceeds the configured size bound.');
            }
            hash_update($hash, $chunk);
            if (strlen($head) < 16) {
                $head .= substr($chunk, 0, 16 - strlen($head));
            }
        }

        $sha = hash_final($hash);

        return new ObservedObject($size > 0, $size, $sha, FileMagic::detect($head), $sha);
    }

    private function pdfHasActiveContent(string $haystack): bool
    {
        foreach (['/JavaScript', '/JS ', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return substr_count($haystack, '/Type /Page') - substr_count($haystack, '/Type /Pages') > $this->maxPages;
    }

    /**
     * @param  array{seen_sig: bool, ihdr: bool, width: int, height: int, chunks: int, buf: string, ended: bool}  $png
     */
    private function consumePng(array &$png): ?string
    {
        if ($png['ended'] === true && $png['buf'] !== '') {
            return 'malformed';
        }

        if ($png['seen_sig'] === false) {
            if (strlen($png['buf']) < 8) {
                return null;
            }
            if (! str_starts_with($png['buf'], "\x89PNG\r\n\x1A\n")) {
                return 'malformed';
            }
            $png['seen_sig'] = true;
            $png['buf'] = substr($png['buf'], 8);
        }

        while (strlen($png['buf']) >= 12) {
            $len = unpack('N', substr($png['buf'], 0, 4));
            if (! is_array($len)) {
                return 'malformed';
            }
            $length = (int) $len[1];
            if ($length < 0 || $length > 8_388_608) {
                return 'malformed';
            }
            $need = 12 + $length;
            if (strlen($png['buf']) < $need) {
                return null;
            }
            $type = substr($png['buf'], 4, 4);
            $data = substr($png['buf'], 8, $length);
            $png['buf'] = substr($png['buf'], $need);
            $png['chunks']++;
            if ($png['chunks'] > 256) {
                return 'malformed';
            }
            if ($png['ihdr'] === false) {
                if ($type !== 'IHDR' || $length !== 13) {
                    return 'malformed';
                }
                $dims = unpack('Nwidth/Nheight', substr($data, 0, 8));
                if (! is_array($dims)) {
                    return 'malformed';
                }
                $png['width'] = (int) $dims['width'];
                $png['height'] = (int) $dims['height'];
                if ($png['width'] < 1 || $png['height'] < 1 || $png['width'] > $this->maxImageEdge || $png['height'] > $this->maxImageEdge) {
                    return 'malformed';
                }
                $png['ihdr'] = true;

                continue;
            }
            if ($type === 'IHDR') {
                return 'malformed';
            }
            if ($type === 'IEND') {
                $png['ended'] = true;
            }
        }

        return null;
    }

    /**
     * @param  array{sof: bool, width: int, height: int, eoi: bool}  $jpeg
     */
    private function consumeJpeg(array &$jpeg, string $chunk, string $head, bool $isFirst): ?string
    {
        if ($isFirst && (strlen($head) < 3 || $head[0] !== "\xFF" || $head[1] !== "\xD8" || $head[2] !== "\xFF")) {
            return 'malformed';
        }

        if (str_contains($chunk, "\xFF\xD9")) {
            $jpeg['eoi'] = true;
        }

        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length - 3) {
            if ($chunk[$offset] !== "\xFF") {
                $offset++;

                continue;
            }
            $marker = ord($chunk[$offset + 1]);
            if ($marker === 0xC0 || $marker === 0xC2) {
                if ($offset + 9 >= $length) {
                    break;
                }
                $height = (ord($chunk[$offset + 5]) << 8) + ord($chunk[$offset + 6]);
                $width = (ord($chunk[$offset + 7]) << 8) + ord($chunk[$offset + 8]);
                if ($width < 1 || $height < 1 || $width > $this->maxImageEdge || $height > $this->maxImageEdge) {
                    return 'malformed';
                }
                $jpeg['sof'] = true;
                $jpeg['width'] = $width;
                $jpeg['height'] = $height;
            }
            $offset++;
        }

        return null;
    }

    private function reject(int $size, string $sha, ?string $mime, string $reason): MediaInspection
    {
        return new MediaInspection(false, $size, $sha, $mime, $sha, $reason);
    }
}
