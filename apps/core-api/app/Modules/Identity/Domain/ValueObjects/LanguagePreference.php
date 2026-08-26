<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

enum LanguagePreference: string
{
    case Arabic = 'ar';
    case English = 'en';
}
