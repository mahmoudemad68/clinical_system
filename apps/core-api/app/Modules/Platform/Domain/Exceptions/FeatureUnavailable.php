<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use RuntimeException;

/**
 * Flag-gated capability is off. Mapped to 404 so existence is not disclosed.
 */
final class FeatureUnavailable extends RuntimeException {}
