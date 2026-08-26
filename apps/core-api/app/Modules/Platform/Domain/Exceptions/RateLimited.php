<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use RuntimeException;

final class RateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many attempts.');
    }
}
