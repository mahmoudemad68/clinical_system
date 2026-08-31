<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\CursorScope;
use Modules\Platform\Support\PaginationCursor;

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
