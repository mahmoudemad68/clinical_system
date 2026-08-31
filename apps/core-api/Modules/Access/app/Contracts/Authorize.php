<?php

declare(strict_types=1);

namespace Modules\Access\Contracts;

use Modules\Access\Support\AuthorizationDecision;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Support\Identifier;

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
