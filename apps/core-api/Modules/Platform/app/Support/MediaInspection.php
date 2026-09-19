<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * One-pass streamed observation plus structural validation of an allowed
 * purpose format. OCR and semantic interpretation are out of scope.
 */
final readonly class MediaInspection
{
    public function __construct(
        public bool $ok,
        public int $sizeBytes,
        public string $sha256,
        public ?string $detectedMime,
        public string $objectVersion,
        public ?string $rejectionReason,
    ) {}
}
