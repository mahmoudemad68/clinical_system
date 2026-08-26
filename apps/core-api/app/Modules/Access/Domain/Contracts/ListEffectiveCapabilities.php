<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\Contracts;

use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use DateTimeImmutable;

interface ListEffectiveCapabilities
{
    /**
     * @return list<string>
     */
    public function forActor(ActorContext $actor, DateTimeImmutable $now): array;
}
