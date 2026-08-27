<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Canonical Egyptian mobile number in E.164.
 *
 * Production operators: 010, 011, 012, 015. Tests may also accept the
 * unallocated 019 prefix used by SyntheticEgyptianData.
 *
 * Messages never echo the input.
 */
final readonly class PhoneE164
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EASTERN_ARABIC_INDIC = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const WESTERN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private function __construct(public string $value) {}

    public static function fromUntrusted(string $raw, bool $allowSynthetic = false): self
    {
        $digits = self::canonicalDigits($raw);

        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '20') === false && str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '20'.substr($digits, 1);
        }

        if (preg_match('/^20(10|11|12|15)\d{8}$/', $digits) === 1) {
            return new self('+'.$digits);
        }

        if ($allowSynthetic && preg_match('/^2019\d{8}$/', $digits) === 1) {
            return new self('+'.$digits);
        }

        throw new InvalidValueObject('Phone number is not a valid Egyptian mobile number.');
    }

    public function e164(): string
    {
        return $this->value;
    }

    /**
     * National significant number without country code, for password-policy
     * comparison only. Never logged.
     */
    public function nationalDigits(): string
    {
        return substr($this->value, 3);
    }

    public function masked(): string
    {
        $digits = substr($this->value, 1);

        return '+'.substr($digits, 0, 4).str_repeat('*', max(0, strlen($digits) - 6)).substr($digits, -2);
    }

    private static function canonicalDigits(string $raw): string
    {
        $converted = str_replace(
            array_merge(self::ARABIC_INDIC, self::EASTERN_ARABIC_INDIC),
            array_merge(self::WESTERN, self::WESTERN),
            $raw,
        );

        $stripped = preg_replace('/[\s\-\.\(\)\+]/u', '', $converted) ?? '';

        if ($stripped === '' || preg_match('/^\d+$/', $stripped) !== 1) {
            throw new InvalidValueObject('Phone number is not a valid Egyptian mobile number.');
        }

        return $stripped;
    }
}
