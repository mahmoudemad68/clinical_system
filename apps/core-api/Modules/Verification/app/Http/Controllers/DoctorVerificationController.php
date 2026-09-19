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
use Modules\Verification\Support\VerificationSubmissionRules;

final class DoctorVerificationController
{
    public function submit(Request $request, VerificationService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, VerificationSubmissionRules::submit());

        return Envelope::ok(
            $handler->submitDoctorCase($this->actor($request), $data)->toArray(),
            $this->requestId($request),
        );
    }

    public function status(Request $request, VerificationService $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->applicantStatus($this->actor($request))->toArray(),
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
