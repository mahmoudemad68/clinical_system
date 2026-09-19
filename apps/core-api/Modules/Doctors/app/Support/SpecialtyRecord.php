<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use Modules\Platform\Support\Identifier;

/**
 * Internal specialty row. Must not leave the Doctors module except as a
 * SpecialtyProjection from ListSpecialties.
 */
final readonly class SpecialtyRecord
{
    public function __construct(
        public Identifier $id,
        public string $code,
        public string $labelAr,
        public string $labelEn,
        public bool $active,
        public int $sortOrder,
    ) {}
}
