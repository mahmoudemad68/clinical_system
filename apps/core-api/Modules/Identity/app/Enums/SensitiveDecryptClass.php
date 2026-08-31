<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

/**
 * Classification of sensitive plaintext decrypt (ADR 0013).
 *
 * Human disclosure is reserved: support screens never receive National ID or
 * full phone. Every current product decrypt is internal processing and is
 * still audited.
 */
enum SensitiveDecryptClass: string
{
    case InternalProcessing = 'internal_processing';
    case HumanDisclosure = 'human_disclosure';
}
