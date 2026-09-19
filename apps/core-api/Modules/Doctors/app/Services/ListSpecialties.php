<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Doctors\Services\Persistence\PostgresSpecialtyStore;
use Modules\Doctors\Support\SpecialtyProjection;

/**
 * Doctors-owned specialty catalog query. Active specialties only, ordered by
 * sort_order then code. No HTTP surface in this slice.
 */
final class ListSpecialties
{
    public function __construct(
        private readonly PostgresSpecialtyStore $store,
    ) {}

    /**
     * @return list<SpecialtyProjection>
     */
    public function handle(): array
    {
        $out = [];
        foreach ($this->store->listActive() as $row) {
            $out[] = new SpecialtyProjection(
                $row->id->value,
                $row->code,
                $row->labelAr,
                $row->labelEn,
                $row->sortOrder,
            );
        }

        return $out;
    }
}
