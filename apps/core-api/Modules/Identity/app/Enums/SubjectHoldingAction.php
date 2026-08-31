<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

/**
 * Technical action for one Phase-01 holding during subject erasure.
 *
 * These are engineering operations. They are not statutory retention periods
 * and do not record a PDPL lawful basis.
 */
enum SubjectHoldingAction: string
{
    case Delete = 'DELETE';
    case NullSensitiveFields = 'NULL_SENSITIVE_FIELDS';
    case IrreversibleTombstone = 'IRREVERSIBLE_TOMBSTONE';
    case HmacLookupTombstone = 'HMAC_LOOKUP_TOMBSTONE';
    case PreserveSecurityAudit = 'PRESERVE_SECURITY_AUDIT';
    case NotSubjectLinked = 'NOT_SUBJECT_LINKED';
}
