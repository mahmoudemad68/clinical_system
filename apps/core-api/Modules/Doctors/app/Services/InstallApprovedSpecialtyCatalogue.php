<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Modules\Doctors\Exceptions\ConflictingSpecialtyCatalogue;
use Modules\Doctors\Support\ApprovedSpecialtyCatalogueV1;
use stdClass;

/**
 * Installs the independently approved Phase 02 specialty catalogue.
 *
 * Accepted states: empty table, or a table that already exactly equals this
 * version (stable UUIDv7, code, labels, active, sort_order). Any other
 * catalogue fails closed without mutation.
 */
final class InstallApprovedSpecialtyCatalogue
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function install(): void
    {
        $approved = ApprovedSpecialtyCatalogueV1::rows();
        $existing = $this->existingRows();

        if ($existing->count() === 0) {
            $this->insertApproved($approved);

            return;
        }

        if ($this->exactlyMatches($existing, $approved)) {
            return;
        }

        throw $this->conflict($existing, $approved, 'install');
    }

    public function rollBack(): void
    {
        $approved = ApprovedSpecialtyCatalogueV1::rows();
        $existing = $this->existingRows();

        if ($existing->count() === 0) {
            return;
        }

        if (! $this->exactlyMatches($existing, $approved)) {
            throw $this->conflict($existing, $approved, 'rollback');
        }

        $ids = array_map(static fn (array $row): string => $row['id'], $approved);
        $referenced = $this->referencedSpecialtyIds($ids);
        if ($referenced !== []) {
            throw new ConflictingSpecialtyCatalogue(
                'Refusing to roll back approved specialty catalogue '.ApprovedSpecialtyCatalogueV1::VERSION
                .': one or more catalogue rows are referenced by doctor_profiles. Referenced specialty ids: '
                .implode(', ', $referenced).'.',
            );
        }

        $this->connection->table('specialties')->whereIn('id', $ids)->delete();
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function existingRows(): Collection
    {
        return $this->connection->table('specialties')
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  Collection<int, stdClass>  $existing
     * @param  list<array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}>  $approved
     */
    private function exactlyMatches(Collection $existing, array $approved): bool
    {
        if ($existing->count() !== count($approved)) {
            return false;
        }

        $approvedByCode = $this->indexApproved($approved);
        foreach ($existing as $row) {
            $code = (string) $row->code;
            if (! isset($approvedByCode[$code])) {
                return false;
            }
            if (! $this->rowEquals($row, $approvedByCode[$code])) {
                return false;
            }
            unset($approvedByCode[$code]);
        }

        return $approvedByCode === [];
    }

    /**
     * @param  list<array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}>  $approved
     */
    private function insertApproved(array $approved): void
    {
        $now = new DateTimeImmutable(ApprovedSpecialtyCatalogueV1::RELEASE_DATE.'T00:00:00+00:00');
        $rows = [];
        foreach ($approved as $row) {
            $rows[] = [
                'id' => $row['id'],
                'code' => $row['code'],
                'label_ar' => $row['label_ar'],
                'label_en' => $row['label_en'],
                'active' => $row['active'],
                'sort_order' => $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->connection->transaction(function () use ($rows): void {
            $this->connection->table('specialties')->insert($rows);
        });
    }

    /**
     * @param  Collection<int, stdClass>  $existing
     * @param  list<array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}>  $approved
     */
    private function conflict(Collection $existing, array $approved, string $operation): ConflictingSpecialtyCatalogue
    {
        $approvedByCode = $this->indexApproved($approved);
        $existingCodes = [];
        $mismatched = [];
        foreach ($existing as $row) {
            $code = (string) $row->code;
            $existingCodes[] = $code;
            if (! isset($approvedByCode[$code])) {
                continue;
            }
            if (! $this->rowEquals($row, $approvedByCode[$code])) {
                $mismatched[] = $code;
            }
        }

        $approvedCodes = array_map(static fn (array $row): string => $row['code'], $approved);
        $unexpected = array_values(array_diff($existingCodes, $approvedCodes));
        $missing = array_values(array_diff($approvedCodes, $existingCodes));
        $existingIds = [];
        foreach ($existing as $row) {
            $existingIds[] = (string) $row->id;
        }
        $referenced = $this->referencedSpecialtyIds($existingIds);

        $parts = [
            'Fail-closed specialty catalogue '.$operation.' for '.ApprovedSpecialtyCatalogueV1::VERSION.'.',
            'The specialties table is not empty and does not exactly equal the approved '
            .ApprovedSpecialtyCatalogueV1::ROW_COUNT.'-row reference.',
            'Existing row count: '.$existing->count().'.',
        ];
        if ($unexpected !== []) {
            $parts[] = 'Unexpected specialty codes: '.implode(', ', $unexpected).'.';
        }
        if ($missing !== []) {
            $parts[] = 'Missing approved specialty codes: '.implode(', ', $missing).'.';
        }
        if ($mismatched !== []) {
            $parts[] = 'Approved codes with a different id, label, active flag, or sort_order: '.implode(', ', $mismatched).'.';
        }
        if ($referenced !== []) {
            $parts[] = 'Conflicting rows are referenced by doctor_profiles (specialty ids: '
                .implode(', ', $referenced).'). Refusing to rewrite a doctor specialty.';
        }
        $parts[] = 'Refusing to overwrite, delete, activate, or leave unauthorized rows in place.';

        return new ConflictingSpecialtyCatalogue(implode(' ', $parts));
    }

    /**
     * @param  list<array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}>  $approved
     * @return array<string, array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}>
     */
    private function indexApproved(array $approved): array
    {
        $out = [];
        foreach ($approved as $row) {
            $out[$row['code']] = $row;
        }

        return $out;
    }

    /**
     * @param  array{id: string, code: string, label_ar: string, label_en: string, active: bool, sort_order: int}  $expected
     */
    private function rowEquals(stdClass $row, array $expected): bool
    {
        return (string) $row->id === $expected['id']
            && (string) $row->code === $expected['code']
            && (string) $row->label_ar === $expected['label_ar']
            && (string) $row->label_en === $expected['label_en']
            && $this->asBool($row->active) === $expected['active']
            && (int) $row->sort_order === $expected['sort_order'];
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 't' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'f' || $value === 'false' || $value === null) {
            return false;
        }

        return (bool) $value;
    }

    /**
     * @param  list<string>  $specialtyIds
     * @return list<string>
     */
    private function referencedSpecialtyIds(array $specialtyIds): array
    {
        if ($specialtyIds === []) {
            return [];
        }

        return $this->connection->table('doctor_profiles')
            ->whereIn('specialty_id', $specialtyIds)
            ->distinct()
            ->orderBy('specialty_id')
            ->pluck('specialty_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
