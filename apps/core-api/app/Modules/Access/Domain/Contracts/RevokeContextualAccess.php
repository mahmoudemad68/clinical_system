<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

interface RevokeContextualAccess
{
    public function revoke(Identifier $grantId, DateTimeImmutable $now): void;
}
