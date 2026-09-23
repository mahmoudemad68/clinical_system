<?php

declare(strict_types=1);

namespace Modules\Admin\Services;

use Modules\Identity\Support\ActorContext;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Services\VerificationUploadService;
use Modules\Verification\Support\AdminDoctorApplicantOutcome;
use Modules\Verification\Support\VerificationSubmissionOutcome;

/**
 * Admin HTTP facade for Admin-created doctor applicants. Delegates to
 * Verification public services. Does not query Doctors or Verification tables.
 */
final class AdminDoctorApplicantService
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly VerificationUploadService $uploads,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(ActorContext $actor, array $input): AdminDoctorApplicantOutcome
    {
        return $this->verification->createAdminDoctorApplicant($actor, $input);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function specialties(ActorContext $actor): array
    {
        return $this->verification->listSpecialtiesForAdminCreate($actor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{projection: mixed, grant: mixed}
     */
    public function createUpload(ActorContext $actor, Identifier $doctorId, array $input): array
    {
        return $this->uploads->createRepresentedDoctorUpload($actor, $doctorId, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function submit(ActorContext $actor, Identifier $doctorId, array $input): VerificationSubmissionOutcome
    {
        return $this->verification->submitRepresentedDoctorCase($actor, $doctorId, $input);
    }
}
