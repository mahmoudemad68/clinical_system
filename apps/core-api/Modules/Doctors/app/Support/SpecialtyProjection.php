<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

/**
 * Safe specialty catalog projection. Public labels only.
 *
 * @phpstan-type ProjectionArray array{
 *     specialty_id: string,
 *     code: string,
 *     label_ar: string,
 *     label_en: string,
 *     sort_order: int
 * }
 */
final readonly class SpecialtyProjection
{
    public function __construct(
        public string $specialtyId,
        public string $code,
        public string $labelAr,
        public string $labelEn,
        public int $sortOrder,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'specialty_id' => $this->specialtyId,
            'code' => $this->code,
            'label_ar' => $this->labelAr,
            'label_en' => $this->labelEn,
            'sort_order' => $this->sortOrder,
        ];
    }
}
