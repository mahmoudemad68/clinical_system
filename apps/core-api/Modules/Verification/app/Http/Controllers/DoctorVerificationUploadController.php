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
use Modules\Verification\Services\VerificationUploadService;
use Modules\Verification\Support\VerificationUploadRules;

final class DoctorVerificationUploadController
{
    public function create(Request $request, VerificationUploadService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, VerificationUploadRules::create());
        $result = $handler->createDoctorUpload($this->actor($request), $data);
        $grant = $result['grant'];

        return Envelope::ok(
            $result['projection']->toArray() + [
                'upload_target' => [
                    'method' => $grant->method,
                    'url' => $grant->url,
                    'headers' => $grant->headers,
                    'expires_at' => $result['projection']->expiresAt,
                ],
            ],
            $this->requestId($request),
            status: 201,
        );
    }

    public function complete(Request $request, string $uploadId, VerificationUploadService $handler): JsonResponse
    {
        ClosedJsonValidator::validate($request, VerificationUploadRules::complete());

        return Envelope::ok(
            $handler->completeDoctorUpload($this->actor($request), Identifier::fromString($uploadId))->toArray(),
            $this->requestId($request),
        );
    }

    public function show(Request $request, string $uploadId, VerificationUploadService $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->doctorUploadStatus($this->actor($request), Identifier::fromString($uploadId))->toArray(),
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
