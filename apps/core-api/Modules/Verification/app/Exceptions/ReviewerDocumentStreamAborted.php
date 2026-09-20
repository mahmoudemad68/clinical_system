<?php

declare(strict_types=1);

namespace Modules\Verification\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Fail-closed abort after a reviewer stream cannot emit the trusted byte count.
 * Headers may already have been sent; the connection must not complete as a
 * valid document. Safe: no locator, hash, or provider detail.
 */
final class ReviewerDocumentStreamAborted extends RuntimeException implements ShouldntReport
{
    public static function incomplete(): self
    {
        return new self('Reviewer document stream ended before the trusted size.');
    }
}
