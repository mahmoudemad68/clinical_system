<?php

declare(strict_types=1);

namespace Modules\Auth\Enums;

enum DevicePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Windows = 'windows';
    case Macos = 'macos';
    case Linux = 'linux';
    case Web = 'web';
}
