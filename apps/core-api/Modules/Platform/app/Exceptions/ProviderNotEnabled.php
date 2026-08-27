<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * A provider capability is not enabled in this phase or environment.
 *
 * Fail closed. Callers must not invent a local fallback that bypasses the
 * owning port (interface segregation, Phase 00 SOLID).
 */
final class ProviderNotEnabled extends RuntimeException {}
