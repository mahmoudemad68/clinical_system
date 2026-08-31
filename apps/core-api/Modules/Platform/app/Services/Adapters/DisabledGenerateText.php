<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\GenerateText;
use Modules\Platform\Exceptions\ProviderNotEnabled;

/** Fail-closed generator. Phase 16 supplies the model adapter. */
final class DisabledGenerateText implements GenerateText
{
    public function generate(string $promptRef, array $options): string
    {
        throw new ProviderNotEnabled('GenerateText is not enabled in Phase 00.');
    }
}
