<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use DateTimeImmutable;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

/**
 * Append-only demographic provenance. Never records National ID.
 */
final class PatientDemographicRevisionRecorder
{
    /** @var list<string> */
    private const CREATION_FIELDS = [
        'full_name',
        'gender',
        'date_of_birth',
        'height_cm',
        'weight_kg',
        'marital_status',
        'blood_type',
    ];

    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly NationalIdProtector $protector,
        private readonly IdentityGenerator $ids,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function recordAcceptedFields(
        Identifier $profileId,
        array $input,
        string $actorType,
        Identifier $actorId,
        string $reasonCode,
        string $sourceType,
        int $profileVersion,
        Identifier $requestId,
        DateTimeImmutable $now,
    ): void {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        foreach (self::CREATION_FIELDS as $field) {
            if (! array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
                continue;
            }

            $row = [
                'id' => $this->ids->next()->value,
                'patient_profile_id' => $profileId->value,
                'field_name' => $field,
                'old_protected' => null,
                'new_protected' => null,
                'old_plain' => null,
                'new_plain' => null,
                'actor_type' => $actorType,
                'actor_id' => $actorId->value,
                'reason_code' => $reasonCode,
                'source_type' => $sourceType,
                'profile_version' => $profileVersion,
                'request_id' => $requestId->value,
                'created_at' => $stamp,
            ];

            if ($field === 'full_name') {
                $cipher = $this->protector->encryptSecret('patient_full_name', (string) $input['full_name']);
                $row['new_protected'] = BinaryColumn::bind($cipher);
            } else {
                $row['new_plain'] = is_scalar($input[$field]) ? (string) $input[$field] : null;
            }

            $this->store->insertRevision($row);
        }
    }
}
