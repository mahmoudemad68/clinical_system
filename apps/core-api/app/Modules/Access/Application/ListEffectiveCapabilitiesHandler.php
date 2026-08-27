<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Access\Domain\Contracts\ListEffectiveCapabilities;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use DateTimeImmutable;

final class ListEffectiveCapabilitiesHandler implements ListEffectiveCapabilities
{
    public function __construct(private readonly GrantStore $grants) {}

    public function forActor(ActorContext $actor, DateTimeImmutable $now): array
    {
        $fromGrants = $this->grants->activeCapabilities($actor->userId, $now);
        $knownGrants = array_values(array_filter(
            $fromGrants,
            static fn (string $capability): bool => Capabilities::isGrantable($capability),
        ));

        $merged = array_values(array_unique([...$actor->capabilities, ...$knownGrants]));
        sort($merged);

        return $merged;
    }
}
