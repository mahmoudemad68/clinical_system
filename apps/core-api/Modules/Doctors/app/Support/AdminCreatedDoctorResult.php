<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

/**
 * Compact Admin-created doctor result. Case linkage is owned by Verification.
 */
final readonly class AdminCreatedDoctorResult
{
    public const CREATED = 'created';

    public const ALREADY_EXISTS = 'already_exists';

    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public function __construct(
        public string $status,
        public ?string $doctorId,
        public ?int $version,
    ) {}

    public static function created(string $doctorId, int $version): self
    {
        return new self(self::CREATED, $doctorId, $version);
    }

    public static function alreadyExists(string $doctorId, int $version): self
    {
        return new self(self::ALREADY_EXISTS, $doctorId, $version);
    }

    public static function manualReview(): self
    {
        return new self(self::MANUAL_REVIEW_REQUIRED, null, null);
    }
}
