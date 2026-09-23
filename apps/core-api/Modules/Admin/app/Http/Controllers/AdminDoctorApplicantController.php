<?php

declare(strict_types=1);

namespace Modules\Admin\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\AdminDoctorApplicantService;
use Modules\Admin\Support\AdminDoctorApplicantRules;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Support\VerificationSubmissionRules;
use Modules\Verification\Support\VerificationUploadRules;

final class AdminDoctorApplicantController
{
    public function specialties(Request $request, AdminDoctorApplicantService $handler): JsonResponse
    {
        return Envelope::ok(
            ['specialties' => $handler->specialties($this->actor($request))],
            $this->requestId($request),
        );
    }

    public function store(Request $request, AdminDoctorApplicantService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, AdminDoctorApplicantRules::create());
        $outcome = $handler->create($this->actor($request), $data);
        $payload = $outcome->toArray();

        if (($payload['status'] ?? '') === 'created') {
            return Envelope::created($payload, $this->requestId($request));
        }

        return Envelope::ok($payload, $this->requestId($request));
    }

    public function createUpload(Request $request, string $doctorId, AdminDoctorApplicantService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, VerificationUploadRules::create());
        $result = $handler->createUpload($this->actor($request), Identifier::fromString($doctorId), $data);
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

    public function submit(Request $request, string $doctorId, AdminDoctorApplicantService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, VerificationSubmissionRules::submit());

        return Envelope::ok(
            $handler->submit($this->actor($request), Identifier::fromString($doctorId), $data)->toArray(),
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
