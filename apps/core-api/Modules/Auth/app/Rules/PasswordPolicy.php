<?php

declare(strict_types=1);

namespace Modules\Auth\Rules;

use Modules\Identity\Support\PhoneE164;
use Modules\Platform\Exceptions\InvalidValueObject;

final class PasswordPolicy
{
    public function __construct(
        private readonly int $minLength = 12,
        private readonly int $maxLength = 128,
    ) {}

    public function assert(string $plain, ?PhoneE164 $phone = null): void
    {
        $length = strlen($plain);

        if ($length < $this->minLength || $length > $this->maxLength) {
            throw new InvalidValueObject('Password does not meet the required policy.');
        }

        if (preg_match('/^\d+$/', $plain) === 1) {
            throw new InvalidValueObject('Password does not meet the required policy.');
        }

        if ($phone !== null && str_contains($plain, $phone->nationalDigits())) {
            throw new InvalidValueObject('Password does not meet the required policy.');
        }
    }
}
