<?php

declare(strict_types=1);

namespace Modules\Admin\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\AdminVerificationReviewService;
use Modules\Admin\Support\AdminVerificationRules;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class AdminVerificationController
{
    public function index(Request $request, AdminVerificationReviewService $handler): JsonResponse
    {
        $page = $handler->queue($this->actor($request), $request);

        return Envelope::ok(
            $page['items'],
            $this->requestId($request),
            ['pagination' => $page['pagination']],
        );
    }

    public function show(Request $request, string $caseId, AdminVerificationReviewService $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->show($this->actor($request), Identifier::fromString($caseId)),
            $this->requestId($request),
        );
    }

    public function claim(Request $request, string $caseId, AdminVerificationReviewService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, AdminVerificationRules::claim());

        return Envelope::ok(
            $handler->claim($this->actor($request), Identifier::fromString($caseId), $data),
            $this->requestId($request),
        );
    }

    public function decide(Request $request, string $caseId, AdminVerificationReviewService $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, AdminVerificationRules::decide());

        return Envelope::ok(
            $handler->decide($this->actor($request), Identifier::fromString($caseId), $data),
            $this->requestId($request),
        );
    }

    public function documentAccess(
        Request $request,
        string $caseId,
        string $documentId,
        AdminVerificationReviewService $handler,
    ): JsonResponse {
        ClosedJsonValidator::validate($request, AdminVerificationRules::documentAccess());

        return Envelope::ok(
            $handler->documentAccess(
                $this->actor($request),
                Identifier::fromString($caseId),
                Identifier::fromString($documentId),
            ),
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
