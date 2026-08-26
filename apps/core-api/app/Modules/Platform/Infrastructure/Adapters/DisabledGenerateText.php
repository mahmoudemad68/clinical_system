<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Adapters;

use App\Modules\Platform\Domain\Contracts\GenerateText;
use App\Modules\Platform\Domain\Exceptions\ProviderNotEnabled;

/** Fail-closed generator. Phase 16 supplies the model adapter. */
final class DisabledGenerateText implements GenerateText
{
    public function generate(string $promptRef, array $options): string
    {
        throw new ProviderNotEnabled('GenerateText is not enabled in Phase 00.');
    }
}
