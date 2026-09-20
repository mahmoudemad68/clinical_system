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
        $pdfCarry = '';
        $pdfPages = 0;
        $png = ['seen_sig' => false, 'ihdr' => false, 'width' => 0, 'height' => 0, 'chunks' => 0, 'buf' => '', 'ended' => false];
        $jpeg = ['soi' => false, 'sof' => false, 'width' => 0, 'height' => 0, 'eoi' => false, 'buf' => ''];

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
                $pdfPages += $this->accumulatePdfPages($pdfCarry, $chunk);
                if ($pdfPages > $this->maxPages) {
                    return $this->reject($size, hash_final($hash), FileMagic::PDF, 'malformed');
                }
            } elseif ($detected === FileMagic::PNG) {
                $png['buf'] .= $chunk;
                $pngReason = $this->consumePng($png);
                if (is_string($pngReason)) {
                    return $this->reject($size, hash_final($hash), FileMagic::PNG, $pngReason);
                }
            } elseif ($detected === FileMagic::JPEG) {
                $jpegReason = $this->consumeJpeg($jpeg, $chunk);
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
            if (! str_contains($tail, '%%EOF') || $this->pdfHasTrailingPayload($tail) || $pdfPages > $this->maxPages) {
                return $this->reject($size, $sha, $detectedMime, 'malformed');
            }
        }

        if ($detectedMime === FileMagic::PNG && ($png['ended'] !== true || $png['ihdr'] !== true || $png['buf'] !== '')) {
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

        return new ObservedObject($size > 0, $size, $sha, FileMagic::detect($head), '');
    }

    private function pdfHasActiveContent(string $haystack): bool
    {
        foreach (['/JavaScript', '/JS ', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function accumulatePdfPages(string &$carry, string $chunk): int
    {
        $haystack = $carry.$chunk;
        $pages = substr_count($haystack, '/Type /Page') - substr_count($carry, '/Type /Page');
        $trees = substr_count($haystack, '/Type /Pages') - substr_count($carry, '/Type /Pages');
        $carry = substr($haystack, -12);

        return $pages - $trees;
    }

    private function pdfHasTrailingPayload(string $tail): bool
    {
        $pos = strrpos($tail, '%%EOF');
        if ($pos === false) {
            return true;
        }

        $after = substr($tail, $pos + 5);

        return preg_match('/\A[ \t\r\n]*\z/', $after) !== 1;
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
                if ($png['buf'] !== '') {
                    return 'malformed';
                }
            }
        }

        return null;
    }

    /**
     * @param  array{soi: bool, sof: bool, width: int, height: int, eoi: bool, buf: string}  $jpeg
     */
    private function consumeJpeg(array &$jpeg, string $chunk): ?string
    {
        if ($jpeg['eoi'] === true && $chunk !== '') {
            return 'malformed';
        }

        $jpeg['buf'] .= $chunk;

        if ($jpeg['soi'] === false) {
            if (strlen($jpeg['buf']) < 3) {
                return null;
            }
            if ($jpeg['buf'][0] !== "\xFF" || $jpeg['buf'][1] !== "\xD8" || $jpeg['buf'][2] !== "\xFF") {
                return 'malformed';
            }
            $jpeg['soi'] = true;
        }

        $eoi = strpos($jpeg['buf'], "\xFF\xD9");
        if ($eoi !== false) {
            if (substr($jpeg['buf'], $eoi + 2) !== '') {
                return 'malformed';
            }
            $jpeg['eoi'] = true;
        }

        $offset = 0;
        $length = strlen($jpeg['buf']);
        while ($offset < $length - 3) {
            if ($jpeg['buf'][$offset] !== "\xFF") {
                $offset++;

                continue;
            }
            $marker = ord($jpeg['buf'][$offset + 1]);
            if ($marker === 0xC0 || $marker === 0xC2) {
                if ($offset + 9 >= $length) {
                    break;
                }
                $height = (ord($jpeg['buf'][$offset + 5]) << 8) + ord($jpeg['buf'][$offset + 6]);
                $width = (ord($jpeg['buf'][$offset + 7]) << 8) + ord($jpeg['buf'][$offset + 8]);
                if ($width < 1 || $height < 1 || $width > $this->maxImageEdge || $height > $this->maxImageEdge) {
                    return 'malformed';
                }
                $jpeg['sof'] = true;
                $jpeg['width'] = $width;
                $jpeg['height'] = $height;
            }
            $offset++;
        }

        if ($jpeg['eoi'] === true) {
            $jpeg['buf'] = '';
        } elseif (str_ends_with($jpeg['buf'], "\xFF")) {
            $jpeg['buf'] = "\xFF";
        } elseif (strlen($jpeg['buf']) > 16) {
            $jpeg['buf'] = substr($jpeg['buf'], -16);
        }

        return null;
    }

    private function reject(int $size, string $sha, ?string $mime, string $reason): MediaInspection
    {
        return new MediaInspection(false, $size, $sha, $mime, $sha, $reason);
    }
}
