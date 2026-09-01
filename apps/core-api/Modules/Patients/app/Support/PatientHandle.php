<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use Modules\Platform\Support\Identifier;

/**
 * Opaque patient identifier for internal callers (Phase 03 booking).
 * Never carries National ID history.
 */
final readonly class PatientHandle
{
    public function __construct(
        public Identifier $patientId,
        public string $status,
    ) {}
}
