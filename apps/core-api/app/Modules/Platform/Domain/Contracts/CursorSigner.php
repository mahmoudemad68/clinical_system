<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\CursorScope;
use App\Modules\Platform\Domain\ValueObjects\PaginationCursor;

/**
 * Signs and verifies opaque pagination cursors.
 *
 * The domain owns cursor shape and scope. Signing lives behind this port so
 * Domain never touches application secrets (PaginationCursor docblock).
 */
interface CursorSigner
{
    /**
     * @throws InvalidValueObject when the encoded form would exceed the size bound
     */
    public function encode(PaginationCursor $cursor): string;

    /**
     * @throws InvalidValueObject when the token is malformed, tampered, oversized, or out of scope
     */
    public function decode(string $token, CursorScope $expectedScope): PaginationCursor;
}
