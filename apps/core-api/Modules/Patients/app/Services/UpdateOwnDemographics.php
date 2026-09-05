<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Illuminate\Validation\ValidationException;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientProfileProjection;
use Modules\Patients\Support\PatientProfileProjector;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

/**
 * Allowlisted demographic corrections with optimistic version and revision history.
 */
final class UpdateOwnDemographics
{
    /** @var list<string> */
    private const EDITABLE = [
        'full_name',
        'gender',
        'date_of_birth',
        'height_cm',
        'weight_kg',
        'marital_status',
        'blood_type',
    ];

    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPatientProfileStore $store,
        private readonly PatientProfileProjector $projector,
        private readonly NationalIdProtector $protector,
        private readonly Authorize $authorize,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, array $input, Identifier $requestId): PatientProfileProjection
    {
        $decision = $this->authorize->decide($actor, Capabilities::PATIENTS_PROFILE_UPDATE_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $expectedVersion = (int) $input['version'];
        $changes = array_intersect_key($input, array_flip(self::EDITABLE));
        if ($changes === []) {
            throw ValidationException::withMessages([
                'version' => 'At least one demographic field is required.',
            ]);
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $changes, $expectedVersion, $requestId): PatientProfileProjection {
            $row = $this->store->findByUserId($actor->userId, true);
            if (! $row instanceof PatientProfileRecord) {
                throw new AuthorizationDenied;
            }

            if ($row->version !== $expectedVersion) {
                throw new VersionConflict;
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $update = ['version' => $expectedVersion + 1, 'updated_at' => $stamp];
            $wrote = false;

            foreach ($changes as $field => $newValue) {
                $revision = $this->diff($row, $field, $newValue);
                if ($revision === null) {
                    continue;
                }

                $wrote = true;
                $update[$field === 'full_name' ? 'full_name_ciphertext' : $field] = $revision['column_value'];
                $this->store->insertRevision([
                    'id' => $this->ids->next()->value,
                    'patient_profile_id' => $row->id->value,
                    'field_name' => $field,
                    'old_protected' => $revision['old_protected'],
                    'new_protected' => $revision['new_protected'],
                    'old_plain' => $revision['old_plain'],
                    'new_plain' => $revision['new_plain'],
                    'actor_type' => 'user',
                    'actor_id' => $actor->userId->value,
                    'reason_code' => 'self_correction',
                    'source_type' => 'self_onboarding',
                    'profile_version' => $expectedVersion + 1,
                    'request_id' => $requestId->value,
                    'created_at' => $stamp,
                ]);
            }

            if (! $wrote) {
                return $this->projector->project($row);
            }

            $affected = $this->store->updateDemographics($row->id, $expectedVersion, $update);
            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $this->audit->append(
                $tx,
                'patient.demographics_updated',
                'patient_profile',
                $row->id,
                ['reason_code' => 'self_correction', 'fields_changed' => implode(',', array_keys($changes))],
                $actor->userId,
                'user',
            );

            $fresh = $this->store->findById($row->id, false);
            assert($fresh instanceof PatientProfileRecord);

            return $this->projector->project($fresh);
        });
    }

    /**
     * @return array{column_value: mixed, old_protected: string|null, new_protected: string|null, old_plain: string|null, new_plain: string|null}|null
     */
    private function diff(PatientProfileRecord $row, string $field, mixed $newValue): ?array
    {
        if ($field === 'full_name') {
            $newName = is_string($newValue) ? $newValue : '';
            $newCipher = $this->protector->encryptSecret('patient_full_name', $newName);

            return [
                'column_value' => BinaryColumn::bind($newCipher),
                'old_protected' => BinaryColumn::bind($row->fullNameCiphertext),
                'new_protected' => BinaryColumn::bind($newCipher),
                'old_plain' => null,
                'new_plain' => null,
            ];
        }

        $normalized = $this->normalize($field, $newValue);
        $current = match ($field) {
            'gender' => $row->gender,
            'date_of_birth' => $row->dateOfBirth,
            'height_cm' => $row->heightCm,
            'weight_kg' => $row->weightKg,
            'marital_status' => $row->maritalStatus,
            default => $row->bloodType,
        };

        if ($this->same($current, $normalized)) {
            return null;
        }

        $column = $normalized;
        if (($field === 'height_cm' || $field === 'weight_kg') && $normalized !== null) {
            $column = $normalized;
        }

        return [
            'column_value' => $column,
            'old_protected' => null,
            'new_protected' => null,
            'old_plain' => $current,
            'new_plain' => $normalized,
        ];
    }

    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($field === 'height_cm' || $field === 'weight_kg') {
            return is_numeric($value) ? (string) $value : null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function same(?string $left, ?string $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.00001;
        }

        return $left === $right;
    }
}
