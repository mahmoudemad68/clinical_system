<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Diagnostics\RecordRoundTripCommand;
use App\Modules\Platform\Application\Diagnostics\RecordRoundTripHandler;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Http\Requests\DiagnosticsRoundTripRequest;
use App\Modules\Platform\Http\Responses\Envelope;
use Illuminate\Http\JsonResponse;

/**
 * The Phase 00 foundation slice endpoint.
 *
 * A reference controller: it authenticates (via middleware), validates
 * transport input (via the form request), builds one command, invokes one
 * handler, and maps the result. It contains no business transition and touches
 * no database (phase file, "Laravel module layout").
 *
 * Later controllers should look exactly like this. If one grows a conditional
 * about business state, that logic belongs in a handler or a domain object.
 */
final class DiagnosticsController
{
    public function __construct(private readonly RecordRoundTripHandler $handler) {}

    public function roundTrip(DiagnosticsRoundTripRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->handler->handle(new RecordRoundTripCommand(
            label: (string) $validated['label'],
            echoDelayMs: (int) ($validated['echo_delay_ms'] ?? 0),
        ));

        // 201: a record was created. The response returns immediately; the
        // outbox worker publishes after commit and the request never waits
        // for it (plan.md section 174).
        return Envelope::created(
            $result->toArray(),
            $this->requestId($request),
            ['locale' => app()->getLocale()],
        );
    }

    private function requestId(DiagnosticsRoundTripRequest $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
