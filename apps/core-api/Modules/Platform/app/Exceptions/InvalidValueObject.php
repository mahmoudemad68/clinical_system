<?php

declare(strict_types=1);

namespace Modules\Platform\Exceptions;

use InvalidArgumentException;

/**
 * A value object was handed something it cannot represent.
 *
 * Messages describe the expectation and never echo the offending value: these
 * messages travel into logs and, mapped through the error catalogue, toward
 * clients. A national ID or a token quoted back in an exception message is the
 * canonical way sensitive data reaches a log file.
 */
final class InvalidValueObject extends InvalidArgumentException {}
