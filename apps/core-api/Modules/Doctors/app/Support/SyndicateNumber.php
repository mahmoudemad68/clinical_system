<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Syndicate identifiers are stored protected. No professional-policy or
 * checksum algorithm is applied: repository policy does not define one.
 */
final class SyndicateNumber
{
    public static function canonical(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidValueObject('Syndicate identifier is not valid.');
        }

        $max = (int) config('doctors_module.syndicate_number_max_length', 64);
        if (mb_strlen($trimmed) > $max) {
            throw new InvalidValueObject('Syndicate identifier is not valid.');
        }

        return $trimmed;
    }
}
