<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use DateTimeImmutable;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\NationalId;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

final class DoctorProfileRowFactory
{
    public function __construct(
        private readonly NationalIdProtector $protector,
        private readonly HmacHasher $hmac,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function attributes(
        Identifier $id,
        Identifier $userId,
        NationalId $nationalId,
        Identifier $specialtyId,
        ?string $syndicateCanonical,
        array $input,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $keyVersion = $this->protector->encryptionVersion();

        $row = [
            'id' => $id->value,
            'user_id' => $userId->value,
            'national_id_ciphertext' => BinaryColumn::bind($this->protector->encryptNationalId($nationalId)),
            'national_id_lookup_hmac' => BinaryColumn::bind($this->protector->nationalIdHmac($nationalId)),
            'national_id_key_version' => $keyVersion,
            'syndicate_number_ciphertext' => null,
            'syndicate_number_lookup_hmac' => null,
            'syndicate_number_key_version' => null,
            'specialty_id' => $specialtyId->value,
            'professional_display_name' => (string) $input['professional_display_name'],
            'verification_status' => DoctorVerificationStatus::Draft->value,
            'public_status' => DoctorPublicStatus::Hidden->value,
            'version' => 1,
            'approved_at' => null,
            'suspended_at' => null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];

        if ($syndicateCanonical !== null) {
            $row['syndicate_number_ciphertext'] = BinaryColumn::bind(
                $this->protector->encryptSecret('syndicate_number', $syndicateCanonical),
            );
            $row['syndicate_number_lookup_hmac'] = BinaryColumn::bind(
                $this->hmac->digest('syndicate_number_lookup', $syndicateCanonical),
            );
            $row['syndicate_number_key_version'] = $keyVersion;
        }

        return $row;
    }
}
