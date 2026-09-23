<?php

declare(strict_types=1);

namespace Modules\Doctors\Enums;

/**
 * How Admin-created verification evidence was obtained. Provenance only;
 * not a certification claim and not a bootstrap flag.
 */
enum DoctorEvidenceSource: string
{
    case InPersonOriginals = 'in_person_originals';
    case CertifiedCopy = 'certified_copy';
}
