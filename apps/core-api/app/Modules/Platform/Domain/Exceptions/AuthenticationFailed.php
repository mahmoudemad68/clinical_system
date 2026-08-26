<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use RuntimeException;

/**
 * Enumeration-safe authentication failure. Mapped to 401 without a reason.
 */
final class AuthenticationFailed extends RuntimeException {}
