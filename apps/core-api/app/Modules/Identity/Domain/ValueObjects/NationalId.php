<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;

/**
 * Canonical 14-digit Egyptian national ID.
 *
 * One reviewed function for Unicode digits, separators, confusables, dates,
 * century, and governorate (Phase 01 invariant 6). Check-digit arithmetic is
 * not applied: published sources disagree, and rejecting a valid ID would lock
 * a real person out. Digit 14 must still be a digit after canonicalization.
 *
 * Century 9 plus an impossible date is accepted only when synthetic IDs are
 * explicitly allowed, so fixtures cannot collide with a real identity.
 *
 * Messages never echo the input.
 */
final readonly class NationalId
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EASTERN_ARABIC_INDIC = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const WESTERN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** @var list<string> */
    private const GOVERNORATES = [
        '01', '02', '03', '04',
        '11', '12', '13', '14', '15', '16', '17', '18', '19',
        '21', '22', '23', '24', '25', '26', '27', '28', '29',
        '31', '32', '33', '34', '35',
        '88',
    ];

    private function __construct(public string $digits) {}

    public static function fromUntrusted(string $raw, bool $allowSynthetic = false): self
    {
        $digits = self::canonicalDigits($raw);

        if (strlen($digits) !== 14) {
            throw new InvalidValueObject('National ID is not in the approved format.');
        }

        if ($allowSynthetic && str_starts_with($digits, '9') && str_starts_with(substr($digits, 1, 6), '999999')) {
            return new self($digits);
        }

        $century = $digits[0];

        if ($century !== '2' && $century !== '3') {
            throw new InvalidValueObject('National ID is not in the approved format.');
        }

        $yearPrefix = $century === '2' ? 1900 : 2000;
        $yy = (int) substr($digits, 1, 2);
        $mm = (int) substr($digits, 3, 2);
        $dd = (int) substr($digits, 5, 2);
        $year = $yearPrefix + $yy;

        if (! checkdate($mm, $dd, $year)) {
            throw new InvalidValueObject('National ID is not in the approved format.');
        }

        $governorate = substr($digits, 7, 2);

        if (! in_array($governorate, self::GOVERNORATES, true)) {
            throw new InvalidValueObject('National ID is not in the approved format.');
        }

        return new self($digits);
    }

    public function canonical(): string
    {
        return $this->digits;
    }

    public function masked(): string
    {
        return substr($this->digits, 0, 1).str_repeat('*', 11).substr($this->digits, -2);
    }

    private static function canonicalDigits(string $raw): string
    {
        $converted = str_replace(
            array_merge(self::ARABIC_INDIC, self::EASTERN_ARABIC_INDIC),
            array_merge(self::WESTERN, self::WESTERN),
            $raw,
        );

        $stripped = preg_replace('/[\s\-\.\/]/u', '', $converted) ?? '';

        if ($stripped === '' || preg_match('/^\d{14}$/', $stripped) !== 1) {
            throw new InvalidValueObject('National ID is not in the approved format.');
        }

        return $stripped;
    }
}
