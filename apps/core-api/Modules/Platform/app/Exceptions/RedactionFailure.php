<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Redaction detected a canary on the export path.
 *
 * In strict (non-production) mode this is thrown rather than swallowed so a
 * test fails instead of a leak shipping. Production records a metric and
 * drops the record; it does not take the process down.
 */
final class RedactionFailure extends RuntimeException {}
