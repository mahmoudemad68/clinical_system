<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\Contracts;

use App\Modules\Access\Domain\ValueObjects\AuthorizationDecision;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

interface Authorize
{
    public function decide(
        ActorContext $actor,
        string $action,
        ?string $resourceType = null,
        ?Identifier $resourceId = null,
        ?string $contextType = null,
        ?Identifier $contextId = null,
    ): AuthorizationDecision;
}
