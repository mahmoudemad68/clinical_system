<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Testing;

use App\Modules\Platform\Domain\ValueObjects\CountryCode;
use Random\Randomizer;

/**
 * Synthetic Egyptian-format test data (Phase 00 §5.5).
 *
 * Generates values that are structurally valid so format validation can be
 * exercised, but that cannot collide with a real person's identifiers.
 *
 * The collision-avoidance strategy is the whole point of this class. A
 * generator that produces plausible national IDs will eventually produce a real
 * one, and a test fixture containing a real national ID is a data breach sitting
 * in version control. Every generator below writes into a value range the real
 * issuing scheme cannot use:
 *
 *   national IDs  century digit 9, which the Egyptian scheme does not assign
 *                 (2 = 1900s, 3 = 2000s), and an impossible birth date
 *   phones        the 019 prefix, which no Egyptian operator uses
 *                 (010 Vodafone, 011 Etisalat, 012 Orange, 015 WE)
 *   names         drawn from a fixed placeholder list, never generated
 *
 * Only ever used in tests, seeders, and load-test data. It has no production
 * caller, and the class lives under Infrastructure/Testing so that is obvious.
 */
final class SyntheticEgyptianData
{
    /**
     * Century digit the real scheme does not assign.
     *
     * 2 means born in the 1900s and 3 means the 2000s. 9 means nothing, so a
     * value starting with it cannot be anyone's real identifier.
     */
    private const IMPOSSIBLE_CENTURY_DIGIT = '9';

    /** Mobile prefix no Egyptian operator has been allocated. */
    private const UNALLOCATED_MOBILE_PREFIX = '019';

    private readonly Randomizer $random;

    public function __construct(?Randomizer $random = null)
    {
        $this->random = $random ?? new Randomizer;
    }

    /**
     * A structurally valid but impossible Egyptian national ID.
     *
     * Shape: C YYMMDD GG SSSS K — 14 digits. The century digit is 9 and the
     * date component is 99-99, neither of which the real scheme produces.
     */
    public function nationalId(): string
    {
        $governorate = str_pad((string) $this->random->getInt(1, 88), 2, '0', STR_PAD_LEFT);
        $serial = str_pad((string) $this->random->getInt(0, 9999), 4, '0', STR_PAD_LEFT);
        $check = (string) $this->random->getInt(0, 9);

        // C YYMMDD GG SSSS K = 1 + 6 + 2 + 4 + 1 = 14 digits.
        // YY=99, MM=99, DD=99 is an impossible date in any century.
        return self::IMPOSSIBLE_CENTURY_DIGIT.'999999'.$governorate.$serial.$check;
    }

    /**
     * A well-formed Egyptian mobile number on an unallocated prefix.
     */
    public function mobileNumber(): string
    {
        $subscriber = str_pad((string) $this->random->getInt(0, 99999999), 8, '0', STR_PAD_LEFT);

        return self::UNALLOCATED_MOBILE_PREFIX.$subscriber;
    }

    /**
     * An email on a reserved TLD.
     *
     * `.invalid` is reserved by RFC 2606 and can never resolve, so a test that
     * accidentally sends mail cannot reach a real inbox.
     */
    public function email(?string $local = null): string
    {
        $local ??= 'synthetic.'.$this->random->getInt(1000, 999999);

        return $local.'@example.invalid';
    }

    /**
     * A placeholder name.
     *
     * Fixed list rather than generated, and obviously synthetic in both scripts,
     * so a name appearing in a screenshot or a bug report is unmistakably fake.
     *
     * @return array{given: string, family: string, arabic: string}
     */
    public function name(): array
    {
        $index = $this->random->getInt(0, 4);

        $given = ['Test', 'Sample', 'Demo', 'Example', 'Synthetic'][$index];
        $family = ['Patient', 'Subject', 'Record', 'Account', 'Profile'][$index];
        $arabic = ['تجريبي', 'عينة', 'اختبار', 'مثال', 'صوري'][$index];

        return ['given' => $given, 'family' => $family, 'arabic' => $arabic];
    }

    /**
     * A point inside Egypt's bounding box, for PostGIS geography columns.
     *
     * @return array{latitude: float, longitude: float}
     */
    public function locationInEgypt(): array
    {
        return [
            'latitude' => $this->random->getFloat(22.0, 31.6),
            'longitude' => $this->random->getFloat(25.0, 35.0),
        ];
    }

    /**
     * Does a value match the national-ID format for this country?
     *
     * Format only. A well-formed number is not a real person's number, and this
     * is never identity verification (that is Phase 01).
     */
    public function matchesNationalIdFormat(string $value): bool
    {
        return preg_match(CountryCode::EG->nationalIdPattern(), $value) === 1;
    }

    /**
     * Is this value guaranteed not to be a real identifier?
     *
     * Asserted by tests so a future change to the generator cannot start
     * producing plausible values without a test failing.
     */
    public function isProvablySynthetic(string $nationalId): bool
    {
        return str_starts_with($nationalId, self::IMPOSSIBLE_CENTURY_DIGIT);
    }
}
