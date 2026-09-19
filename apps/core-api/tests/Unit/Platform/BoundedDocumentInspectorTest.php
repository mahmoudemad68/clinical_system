<?php

declare(strict_types=1);

use Modules\Platform\Support\BoundedDocumentInspector;
use Modules\Platform\Support\FileMagic;

function inspectionStream(string $bytes)
{
    $stream = fopen('php://temp', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('detects pdf, jpeg, and png magic and ignores extensions', function () {
    expect(FileMagic::detect(verificationMinimalPdf()))->toBe(FileMagic::PDF)
        ->and(FileMagic::detect(verificationMinimalPng()))->toBe(FileMagic::PNG)
        ->and(FileMagic::detect(verificationMinimalJpeg()))->toBe(FileMagic::JPEG)
        ->and(FileMagic::detect(verificationZipBytes()))->toBeNull()
        ->and(FileMagic::detect(''))->toBeNull();
});

it('streams a hash without loading the whole object as a string', function () {
    $inspector = new BoundedDocumentInspector;
    $bytes = verificationMinimalPdf();
    $stream = inspectionStream($bytes);
    $result = $inspector->inspect($stream, 20_971_520, FileMagic::PDF, [FileMagic::PDF]);
    fclose($stream);

    expect($result->ok)->toBeTrue()
        ->and($result->sha256)->toBe(hash('sha256', $bytes))
        ->and($result->detectedMime)->toBe(FileMagic::PDF)
        ->and($result->sizeBytes)->toBe(strlen($bytes));
});

it('rejects zero-byte, mismatch, unsupported, truncated pdf, and active pdf content', function () {
    $inspector = new BoundedDocumentInspector;

    $empty = inspectionStream('');
    $zero = $inspector->inspect($empty, 1024, FileMagic::PDF, [FileMagic::PDF]);
    fclose($empty);
    expect($zero->ok)->toBeFalse()->and($zero->rejectionReason)->toBe('zero_byte');

    $png = inspectionStream(verificationMinimalPng());
    $mismatch = $inspector->inspect($png, 1024, FileMagic::PDF, [FileMagic::PDF, FileMagic::PNG]);
    fclose($png);
    expect($mismatch->ok)->toBeFalse()->and($mismatch->rejectionReason)->toBe('mime_mismatch');

    $zip = inspectionStream(verificationZipBytes());
    $unsupported = $inspector->inspect($zip, 1024, FileMagic::PDF, [FileMagic::PDF]);
    fclose($zip);
    expect($unsupported->ok)->toBeFalse()->and($unsupported->rejectionReason)->toBe('unsupported_format');

    $truncated = inspectionStream("%PDF-1.4\nnot-finished");
    $malformed = $inspector->inspect($truncated, 1024, FileMagic::PDF, [FileMagic::PDF]);
    fclose($truncated);
    expect($malformed->ok)->toBeFalse()->and($malformed->rejectionReason)->toBe('malformed');

    $active = inspectionStream(verificationActivePdf());
    $js = $inspector->inspect($active, 4096, FileMagic::PDF, [FileMagic::PDF]);
    fclose($active);
    expect($js->ok)->toBeFalse()->and($js->rejectionReason)->toBe('malformed');
});
