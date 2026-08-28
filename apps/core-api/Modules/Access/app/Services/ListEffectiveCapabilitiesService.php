<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use DateTimeImmutable;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Contracts\ListEffectiveCapabilities;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Support\ActorContext;

final class ListEffectiveCapabilitiesService implements ListEffectiveCapabilities
{
    public function __construct(private readonly GrantStore $grants) {}

    public function forActor(ActorContext $actor, DateTimeImmutable $now): array
    {
        if ($actor->passwordMustChange) {
            return Capabilities::PASSWORD_CHANGE_REQUIRED;
        }

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
