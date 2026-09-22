<?php

declare(strict_types=1);

namespace Modules\Verification\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\PharmacyVerificationRules;

final class PharmacyVerificationController
{
    public function open(Request $request, VerificationService $handler): JsonResponse
    {
        ClosedJsonValidator::validate($request, PharmacyVerificationRules::open());

        return Envelope::ok(
            $handler->openPharmacyCase($this->actor($request))->toArray(),
            $this->requestId($request),
        );
    }

    public function submit(Request $request, VerificationService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, PharmacyVerificationRules::submit());

        return Envelope::ok(
            $handler->submitPharmacyCase($this->actor($request), $data)->toArray(),
            $this->requestId($request),
        );
    }

    public function status(Request $request, VerificationService $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->pharmacyApplicantStatus($this->actor($request))->toArray(),
            $this->requestId($request),
        );
    }

    public function statusForOrganization(Request $request, string $organizationId, VerificationService $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->pharmacyApplicantStatusForOrganization(
                $this->actor($request),
                Identifier::fromString($organizationId),
            )->toArray(),
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
