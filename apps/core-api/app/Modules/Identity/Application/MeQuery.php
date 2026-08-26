<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\Contracts\ListEffectiveCapabilities;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;

final class MeQuery
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly ListEffectiveCapabilities $capabilities,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ActorContext $actor): array
    {
        $decision = $this->authorize->decide($actor, Capabilities::IDENTITY_ME_READ);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return [
            'user_id' => $actor->userId->value,
            'account_type' => $actor->accountType->value,
            'status' => $actor->status->value,
            'language' => $actor->language->value,
            'assurance_level' => $actor->assuranceLevel->value,
            'profile_links' => $actor->profileLinkIds,
        ];
    }

    /**
     * @return list<string>
     */
    public function capabilities(ActorContext $actor): array
    {
        $decision = $this->authorize->decide($actor, Capabilities::IDENTITY_CAPABILITIES_READ);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->capabilities->forActor($actor, $this->clock->now());
    }
}
