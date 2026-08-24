<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Diagnostics;

/**
 * Input to the foundation slice.
 *
 * Built by the controller from an already-validated request. Commands are plain
 * typed data with no framework dependency, so a handler can be exercised in a
 * unit test without an HTTP kernel.
 */
final readonly class RecordRoundTripCommand
{
    public function __construct(
        public string $label,
        public int $echoDelayMs = 0,
    ) {
    }
}
