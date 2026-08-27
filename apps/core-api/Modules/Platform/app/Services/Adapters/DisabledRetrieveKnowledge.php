<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\RetrieveKnowledge;
use Modules\Platform\Exceptions\ProviderNotEnabled;

/** Fail-closed retrieval. Phase 16 supplies the Qdrant adapter. */
final class DisabledRetrieveKnowledge implements RetrieveKnowledge
{
    public function retrieve(string $queryRef, array $filter): array
    {
        throw new ProviderNotEnabled('RetrieveKnowledge is not enabled in Phase 00.');
    }
}
