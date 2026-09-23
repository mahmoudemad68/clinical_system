<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Authoritative state cannot accept the requested transition. Mapped to
 * STATE_CONFLICT (409). Distinct from a stale optimistic version.
 * Expected conflict, not an operational failure.
 */
final class StateConflict extends RuntimeException implements ShouldntReport {}
