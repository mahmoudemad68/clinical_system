<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Enumeration-safe authentication failure. Mapped to 401 without a reason.
 */
final class AuthenticationFailed extends RuntimeException {}
