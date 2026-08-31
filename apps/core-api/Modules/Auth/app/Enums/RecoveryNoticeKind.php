<?php

declare(strict_types=1);

namespace Modules\Auth\Enums;

enum RecoveryNoticeKind: string
{
    case Queued = 'queued';
    case Applied = 'applied';
}
