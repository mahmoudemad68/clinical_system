<?php

declare(strict_types=1);

namespace Modules\Clinics\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clinics\Services\AcceptClinicStaffInvitation;
use Modules\Clinics\Support\ClinicLocationRules;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class ClinicStaffInvitationController
{
    public function accept(Request $request, string $invitationId, AcceptClinicStaffInvitation $handler): JsonResponse
    {
        ClosedJsonValidator::validate($request, ClinicLocationRules::accept());

        return Envelope::ok(
            $handler->handle($this->actor($request), Identifier::fromString($invitationId))->toArray(),
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
