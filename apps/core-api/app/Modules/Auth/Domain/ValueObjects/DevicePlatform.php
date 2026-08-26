<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\ValueObjects;

enum DevicePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Windows = 'windows';
    case Macos = 'macos';
    case Linux = 'linux';
    case Web = 'web';
}
