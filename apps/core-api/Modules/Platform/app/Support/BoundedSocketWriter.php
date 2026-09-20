<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * Fail-closed write-all for protocol frames. fwrite() may return a short
 * write; a single false/zero check is not enough.
 */
final class BoundedSocketWriter
{
    /**
     * @param  resource  $socket
     */
    public static function writeAll(mixed $socket, string $bytes): bool
    {
        if (! is_resource($socket)) {
            return false;
        }

        $length = strlen($bytes);
        if ($length === 0) {
            return true;
        }

        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }
}
