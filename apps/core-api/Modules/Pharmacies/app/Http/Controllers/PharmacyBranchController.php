<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\CreatePharmacyBranch;
use Modules\Pharmacies\Services\GetOwnPharmacyBranches;
use Modules\Pharmacies\Services\InvitePharmacyStaff;
use Modules\Pharmacies\Services\ManagePharmacyMemberships;
use Modules\Pharmacies\Services\UpdatePharmacyBranch;
use Modules\Pharmacies\Support\PharmacyBranchRules;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class PharmacyBranchController
{
    public function store(Request $request, string $organizationId, CreatePharmacyBranch $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, PharmacyBranchRules::create());

        return Envelope::created(
            $handler->handle($this->actor($request), Identifier::fromString($organizationId), $data),
            $this->requestId($request),
        );
    }

    public function index(Request $request, string $organizationId, GetOwnPharmacyBranches $handler): JsonResponse
    {
        $page = $handler->handle($this->actor($request), Identifier::fromString($organizationId), $request);

        return Envelope::ok($page['items'], $this->requestId($request), ['pagination' => $page['pagination']]);
    }

    public function show(Request $request, string $organizationId, string $branchId, GetOwnPharmacyBranches $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->show(
                $this->actor($request),
                Identifier::fromString($organizationId),
                Identifier::fromString($branchId),
            )->toArray(),
            $this->requestId($request),
        );
    }

    public function update(Request $request, string $organizationId, string $branchId, UpdatePharmacyBranch $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, PharmacyBranchRules::update());

        return Envelope::ok(
            $handler->handle(
                $this->actor($request),
                Identifier::fromString($organizationId),
                Identifier::fromString($branchId),
                $data,
            )->toArray(),
            $this->requestId($request),
        );
    }

    public function invite(Request $request, string $organizationId, string $branchId, InvitePharmacyStaff $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, PharmacyBranchRules::invite());
        $outcome = $handler->handle(
            $this->actor($request),
            Identifier::fromString($organizationId),
            Identifier::fromString($branchId),
            $data,
        );

        if ($outcome->created) {
            return Envelope::created($outcome->toArray(), $this->requestId($request));
        }

        return Envelope::ok($outcome->toArray(), $this->requestId($request));
    }

    public function memberships(
        Request $request,
        string $organizationId,
        string $branchId,
        ManagePharmacyMemberships $handler,
    ): JsonResponse {
        return Envelope::ok(
            $handler->list(
                $this->actor($request),
                Identifier::fromString($organizationId),
                Identifier::fromString($branchId),
            ),
            $this->requestId($request),
        );
    }

    public function revokeMembership(
        Request $request,
        string $organizationId,
        string $branchId,
        string $membershipId,
        ManagePharmacyMemberships $handler,
    ): JsonResponse {
        ClosedJsonValidator::validate($request, []);

        return Envelope::ok(
            $handler->revoke(
                $this->actor($request),
                Identifier::fromString($organizationId),
                Identifier::fromString($branchId),
                Identifier::fromString($membershipId),
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
