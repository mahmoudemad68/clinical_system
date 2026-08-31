<?php

declare(strict_types=1);

namespace Modules\Access\Contracts;

use DateTimeImmutable;
use Modules\Identity\Support\ActorContext;

interface ListEffectiveCapabilities
{
    /**
     * @return list<string>
     */
    public function forActor(ActorContext $actor, DateTimeImmutable $now): array;
}
