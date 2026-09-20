<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * A replaceable provider failed in a typed retryable way. Callers must not
 * treat this as a clean or infected verdict.
 */
final class TransientProviderFailure extends RuntimeException {}
