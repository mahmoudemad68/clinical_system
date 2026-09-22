<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\AcceptPharmacyStaffInvitation;
use Modules\Pharmacies\Support\PharmacyBranchRules;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class PharmacyStaffInvitationController
{
    public function accept(Request $request, string $invitationId, AcceptPharmacyStaffInvitation $handler): JsonResponse
    {
        ClosedJsonValidator::validate($request, PharmacyBranchRules::accept());

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
