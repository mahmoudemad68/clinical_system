<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Access\Domain\Contracts\RevokeContextualAccess;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class RevokeContextualAccessHandler implements RevokeContextualAccess
{
    public function __construct(private readonly GrantStore $grants) {}

    public function revoke(Identifier $grantId, DateTimeImmutable $now): void
    {
        $this->grants->revoke($grantId, $now);
    }
}
