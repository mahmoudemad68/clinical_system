<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Optimistic version check failed. Mapped to VERSION_CONFLICT (409).
 */
final class VersionConflict extends RuntimeException {}
