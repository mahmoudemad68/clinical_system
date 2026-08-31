<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Generic denial. Mapped to 404 so existence is not disclosed.
 */
final class AuthorizationDenied extends RuntimeException {}
