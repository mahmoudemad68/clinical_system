<?php

declare(strict_types=1);

namespace Modules\Clinics\Enums;

enum ClinicLocationChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Closed = 'closed';
}
