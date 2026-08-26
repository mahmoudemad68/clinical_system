<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use RuntimeException;

/**
 * A provider capability is not enabled in this phase or environment.
 *
 * Fail closed. Callers must not invent a local fallback that bypasses the
 * owning port (interface segregation, Phase 00 SOLID).
 */
final class ProviderNotEnabled extends RuntimeException {}
