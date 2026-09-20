<?php

declare(strict_types=1);

namespace Modules\Doctors\Services\Persistence;

use Illuminate\Database\ConnectionInterface;
use Modules\Doctors\Support\SpecialtyRecord;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresSpecialtyStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function findById(Identifier $id): ?SpecialtyRecord
    {
        $row = $this->connection->table('specialties')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, SpecialtyRecord>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->table('specialties')->whereIn('id', $ids)->get();
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $mapped = $this->map($row);
                $out[$mapped->id->value] = $mapped;
            }
        }

        return $out;
    }

    public function findActiveById(Identifier $id, bool $lock): ?SpecialtyRecord
    {
        $query = $this->connection->table('specialties')
            ->where('id', $id->value)
            ->where('active', true);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * @return list<SpecialtyRecord>
     */
    public function listActive(): array
    {
        $rows = $this->connection->table('specialties')
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->map($row);
            }
        }

        return $out;
    }

    private function map(stdClass $row): SpecialtyRecord
    {
        return new SpecialtyRecord(
            Identifier::fromTrusted((string) $row->id),
            (string) $row->code,
            (string) $row->label_ar,
            (string) $row->label_en,
            (bool) $row->active,
            (int) $row->sort_order,
        );
    }
}
