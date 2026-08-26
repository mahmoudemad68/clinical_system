<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use RuntimeException;

/**
 * A uniqueness constraint on phone or National ID rejected the write.
 *
 * Callers must not surface which field collided. Registration maps this to the
 * same generic OTP envelope as a first-time create.
 */
final class DuplicateIdentity extends RuntimeException {}
