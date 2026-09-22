<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Enums;

enum PharmacyBranchChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
}
