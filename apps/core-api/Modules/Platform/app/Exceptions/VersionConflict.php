<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Optimistic version check failed. Mapped to VERSION_CONFLICT (409).
 * Expected concurrency, not an operational failure.
 */
final class VersionConflict extends RuntimeException implements ShouldntReport {}
