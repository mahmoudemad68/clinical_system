<?php

declare(strict_types=1);

namespace Modules\Access\Contracts;

use DateTimeImmutable;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Support\Identifier;

interface RevokeContextualAccess
{
    public function revoke(ActorContext $initiator, Identifier $grantId, DateTimeImmutable $now): void;
}
