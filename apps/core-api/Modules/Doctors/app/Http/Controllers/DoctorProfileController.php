<?php

declare(strict_types=1);

namespace Modules\Doctors\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Doctors\Services\GetDoctorProfile;
use Modules\Doctors\Services\ListDoctorSpecialties;
use Modules\Doctors\Services\RegisterDoctor;
use Modules\Doctors\Support\DoctorOnboardingRules;
use Modules\Doctors\Support\SpecialtyProjection;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class DoctorProfileController
{
    public function onboard(Request $request, RegisterDoctor $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, DoctorOnboardingRules::onboarding());
        $outcome = $handler->handle($this->actor($request), $data);

        if ($outcome->created) {
            return Envelope::created($outcome->toArray(), $this->requestId($request));
        }

        return Envelope::ok($outcome->toArray(), $this->requestId($request));
    }

    public function me(Request $request, GetDoctorProfile $handler): JsonResponse
    {
        return Envelope::ok($handler->handle($this->actor($request))->toArray(), $this->requestId($request));
    }

    public function specialties(Request $request, ListDoctorSpecialties $handler): JsonResponse
    {
        return Envelope::ok(
            [
                'specialties' => array_map(
                    static fn (SpecialtyProjection $row): array => $row->toArray(),
                    $handler->handle($this->actor($request)),
                ),
            ],
            $this->requestId($request),
        );
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get(ActorContext::class);
        if (! $actor instanceof ActorContext) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
