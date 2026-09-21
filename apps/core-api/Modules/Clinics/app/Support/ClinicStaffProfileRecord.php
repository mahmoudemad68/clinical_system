<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Clinics module.
 */
final readonly class ClinicStaffProfileRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $userId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
