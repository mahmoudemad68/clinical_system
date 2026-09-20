<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\GetOwnPharmacyOrganization;
use Modules\Pharmacies\Services\RegisterPharmacyOrganization;
use Modules\Pharmacies\Support\PharmacyOnboardingRules;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class PharmacyOrganizationController
{
    public function onboard(Request $request, RegisterPharmacyOrganization $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, PharmacyOnboardingRules::onboarding());
        $outcome = $handler->handle($this->actor($request), $data);

        if ($outcome->created) {
            return Envelope::created($outcome->toArray(), $this->requestId($request));
        }

        return Envelope::ok($outcome->toArray(), $this->requestId($request));
    }

    public function me(Request $request, GetOwnPharmacyOrganization $handler): JsonResponse
    {
        return Envelope::ok($handler->handle($this->actor($request))->toArray(), $this->requestId($request));
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
