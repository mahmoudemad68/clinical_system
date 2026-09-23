<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Clinics\Services\CreateClinicLocation;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Support\Identifier;

/**
 * Testing-only: attempt clinic-location create as an Admin-created doctor
 * through the public Clinics service. Writes compact outcome JSON.
 */
final class ProbeDoctorClinicCapabilityCommand extends Command
{
    protected $signature = 'e2e:probe-doctor-clinic-capability
        {--doctor-id= : Doctor profile UUID}
        {--write= : Absolute /tmp path for the result JSON}';

    protected $description = 'Probe clinic-location capability for an Admin-created doctor (local/testing only).';

    public function handle(CreateClinicLocation $locations): int
    {
        if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            $this->error('This command is disabled outside local/testing.');

            return self::FAILURE;
        }

        $write = (string) $this->option('write');
        if ($write === '' || ! str_starts_with($write, '/tmp/')) {
            $this->error('Pass --write=/tmp/... so results never enter the repository.');

            return self::FAILURE;
        }

        $doctorId = (string) $this->option('doctor-id');
        $profile = DB::table('doctor_profiles')->where('id', $doctorId)->first();
        if ($profile === null) {
            $this->error('Doctor probe input is not available.');

            return self::FAILURE;
        }

        $actor = new ActorContext(
            Identifier::fromTrusted((string) $profile->user_id),
            AccountType::Doctor,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal2Totp,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );

        $httpStatus = 201;
        $errorCode = null;
        try {
            $locations->handle($actor, [
                'public_name' => 'E2E Probe Clinic',
                'address' => '1 Probe Street, Cairo',
                'country_code' => 'EG',
                'latitude' => 30.0444,
                'longitude' => 31.2357,
            ]);
        } catch (AuthorizationDenied|FeatureUnavailable) {
            $httpStatus = 404;
            $errorCode = 'NOT_FOUND';
        }

        $fresh = DB::table('doctor_profiles')->where('id', $doctorId)->first();
        $this->writeResult($write, [
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'verification_status' => is_object($fresh) ? (string) $fresh->verification_status : (string) $profile->verification_status,
            'public_status' => is_object($fresh) ? (string) $fresh->public_status : (string) $profile->public_status,
        ]);
        $this->info('Wrote capability probe result.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeResult(string $path, array $payload): void
    {
        if (file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('Probe result could not be written.');
        }
        @chmod($path, 0600);
    }
}
