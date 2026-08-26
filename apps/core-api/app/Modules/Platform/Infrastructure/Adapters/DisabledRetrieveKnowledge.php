<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Adapters;

use App\Modules\Platform\Domain\Contracts\RetrieveKnowledge;
use App\Modules\Platform\Domain\Exceptions\ProviderNotEnabled;

/** Fail-closed retrieval. Phase 16 supplies the Qdrant adapter. */
final class DisabledRetrieveKnowledge implements RetrieveKnowledge
{
    public function retrieve(string $queryRef, array $filter): array
    {
        throw new ProviderNotEnabled('RetrieveKnowledge is not enabled in Phase 00.');
    }
}
