<?php

declare(strict_types=1);
use Tests\Support\FixtureScanObject;

/**
 * Synthetic, inert media for verification-file tests. Not patient documents.
 */
function verificationMinimalPdf(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
        ."2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n"
        ."3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>endobj\n"
        ."trailer<< /Root 1 0 R >>\n"
        ."%%EOF\n";
}

function verificationAlternatePdf(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
        ."2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n"
        ."3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 4 4] >>endobj\n"
        ."trailer<< /Root 1 0 R >>\n"
        ."%%EOF\n";
}

function verificationEicarPdf(): string
{
    return "%PDF-1.4\n"
        .'% '.FixtureScanObject::EICAR."\n"
        ."1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
        ."2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n"
        ."3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>endobj\n"
        ."trailer<< /Root 1 0 R >>\n"
        ."%%EOF\n";
}

function verificationMinimalPng(): string
{
    $decoded = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    assert(is_string($decoded) && $decoded !== '');

    return $decoded;
}

function verificationMinimalJpeg(): string
{
    $hex = 'FFD8FFE000104A46494600010100000100010000'
        .'FFC0000B080001000101011100'
        .'FFDA0008000100113F00'
        .'FFD9';
    $decoded = hex2bin($hex);
    assert(is_string($decoded) && $decoded !== '');

    return $decoded;
}

function verificationZipBytes(): string
{
    return "PK\x03\x04".str_repeat("\x00", 26);
}

function verificationActivePdf(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj<< /Type /Catalog /Pages 2 0 R /OpenAction << /S /JavaScript /JS (app.alert) >> >>endobj\n"
        ."2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n"
        ."3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>endobj\n"
        ."trailer<< /Root 1 0 R >>\n"
        ."%%EOF\n";
}
