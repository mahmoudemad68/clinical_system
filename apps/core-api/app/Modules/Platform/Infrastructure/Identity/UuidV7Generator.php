<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Identity;

use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use Symfony\Component\Uid\UuidV7;

/**
 * Production UUIDv7 generator, backed by symfony/uid.
 *
 * symfony/uid guarantees monotonicity within a process for identifiers created
 * in the same millisecond, which is what keeps index locality close to
 * append-only on the high-volume tables (ADR 0005).
 *
 * Note what this does not promise: identifiers from two processes, or across a
 * clock adjustment, are not globally ordered. Ordering is a storage performance
 * property here and never a correctness property. Nothing may sort by primary
 * key and call the result chronological.
 */
final class UuidV7Generator implements IdentityGenerator
{
    public function next(): Identifier
    {
        return Identifier::fromTrusted((new UuidV7)->toRfc4122());
    }
}
