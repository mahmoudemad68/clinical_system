<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Authoritative state cannot accept the requested transition. Mapped to
 * STATE_CONFLICT (409). Distinct from a stale optimistic version.
 */
final class StateConflict extends RuntimeException {}
