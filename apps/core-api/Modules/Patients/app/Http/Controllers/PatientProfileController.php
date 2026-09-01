<?php

declare(strict_types=1);

namespace Modules\Patients\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Services\CreatePatientProfile;
use Modules\Patients\Services\GetOwnPatientProfile;
use Modules\Patients\Services\UpdateOwnDemographics;
use Modules\Patients\Support\DemographicRules;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class PatientProfileController
{
    public function onboard(Request $request, CreatePatientProfile $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, DemographicRules::onboarding());
        $outcome = $handler->handle($this->actor($request), $data, $this->requestId($request));

        if ($outcome->created) {
            return Envelope::created($outcome->toArray(), $this->requestId($request));
        }

        return Envelope::ok($outcome->toArray(), $this->requestId($request));
    }

    public function me(Request $request, GetOwnPatientProfile $handler): JsonResponse
    {
        return Envelope::ok($handler->handle($this->actor($request))->toArray(), $this->requestId($request));
    }

    public function updateDemographics(Request $request, UpdateOwnDemographics $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, DemographicRules::patch());

        return Envelope::ok(
            $handler->handle($this->actor($request), $data, $this->requestId($request))->toArray(),
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
