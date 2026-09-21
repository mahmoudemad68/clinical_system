<?php

declare(strict_types=1);

namespace Modules\Clinics\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clinics\Services\CreateClinicLocation;
use Modules\Clinics\Services\GetOwnClinicLocations;
use Modules\Clinics\Services\InviteClinicStaff;
use Modules\Clinics\Services\ManageClinicMemberships;
use Modules\Clinics\Services\UpdateClinicLocation;
use Modules\Clinics\Support\ClinicLocationRules;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Http\Responses\Envelope;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

final class ClinicLocationController
{
    public function store(Request $request, CreateClinicLocation $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, ClinicLocationRules::create());

        return Envelope::created($handler->handle($this->actor($request), $data), $this->requestId($request));
    }

    public function index(Request $request, GetOwnClinicLocations $handler): JsonResponse
    {
        $page = $handler->handle($this->actor($request), $request);

        return Envelope::ok($page['items'], $this->requestId($request), ['pagination' => $page['pagination']]);
    }

    public function show(Request $request, string $locationId, GetOwnClinicLocations $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->show($this->actor($request), Identifier::fromString($locationId))->toArray(),
            $this->requestId($request),
        );
    }

    public function update(Request $request, string $locationId, UpdateClinicLocation $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, ClinicLocationRules::update());

        return Envelope::ok(
            $handler->handle($this->actor($request), Identifier::fromString($locationId), $data)->toArray(),
            $this->requestId($request),
        );
    }

    public function invite(Request $request, string $locationId, InviteClinicStaff $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, ClinicLocationRules::invite());
        $outcome = $handler->handle($this->actor($request), Identifier::fromString($locationId), $data);

        if ($outcome->created) {
            return Envelope::created($outcome->toArray(), $this->requestId($request));
        }

        return Envelope::ok($outcome->toArray(), $this->requestId($request));
    }

    public function memberships(Request $request, string $locationId, ManageClinicMemberships $handler): JsonResponse
    {
        return Envelope::ok(
            $handler->list($this->actor($request), Identifier::fromString($locationId)),
            $this->requestId($request),
        );
    }

    public function revokeMembership(
        Request $request,
        string $locationId,
        string $membershipId,
        ManageClinicMemberships $handler,
    ): JsonResponse {
        ClosedJsonValidator::validate($request, []);

        return Envelope::ok(
            $handler->revoke(
                $this->actor($request),
                Identifier::fromString($locationId),
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
