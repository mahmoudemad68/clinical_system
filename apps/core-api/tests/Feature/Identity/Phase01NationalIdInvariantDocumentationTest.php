<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('does not claim an implemented National ID check-digit in live Phase 01 docs', function () {
    $root = dirname(base_path(), 2);
    $phase01 = (string) file_get_contents($root.'/docs/phases/01_auth_identity_and_access.md');
    $adr = (string) file_get_contents($root.'/docs/adr/0014-national-id-check-digit-deferred.md');

    expect($adr)
        ->toContain('Do not implement a National ID check-digit.')
        ->toContain('Digit 14 must still be a digit after canonicalization.');

    expect($phase01)
        ->toContain('National ID parsing and validation are centralized in one reviewed function')
        ->toContain('structural/format only')
        ->toContain('Digit 14 is treated as a digit after canonicalization, not as an implemented checksum')
        ->toContain('Check-digit / modulus validation is deferred')
        ->toContain('ADR 0014')
        ->toContain('No guessed checksum algorithm may be introduced')
        ->not->toContain('invalid dates/check digits are handled by one reviewed function')
        ->not->toContain('invalid length/date/check data');
});
