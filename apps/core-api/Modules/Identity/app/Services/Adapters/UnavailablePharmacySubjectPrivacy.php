<?php

declare(strict_types=1);

namespace Modules\Identity\Services\Adapters;

use Modules\Identity\Contracts\PharmacySubjectPrivacy;
use Modules\Platform\Support\Identifier;

final class UnavailablePharmacySubjectPrivacy implements PharmacySubjectPrivacy
{
    public function holdings(): array
    {
        return [];
    }

    public function exportCounts(Identifier $userId): array
    {
        return [];
    }

    public function eraseLinked(Identifier $userId): array
    {
        return [];
    }
}
