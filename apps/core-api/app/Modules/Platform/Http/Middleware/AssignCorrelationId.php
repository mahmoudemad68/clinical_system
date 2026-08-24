<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Domain\Contracts\CorrelationScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns or adopts the correlation identifier for this request.
 *
 * A client-supplied X-Request-Id is honoured only when it is a well-formed
 * UUIDv7. That constraint is not cosmetic: the value is echoed in responses and
 * attached to logs, traces, and outbox rows. Accepting arbitrary client text
 * would let a caller inject content into log lines, forge correlation with
 * another user's activity, or blow up the cardinality of anything that groups
 * by it.
 *
 * Runs before authentication so that even a rejected request is traceable.
 */
final class AssignCorrelationId
{
    public function __construct(
        private readonly IdentityGenerator $identities,
        private readonly CorrelationScope $correlation,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->fromClient($request) ?? $this->identities->next();

        $this->correlation->set($correlationId);
        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $correlationId->value);

        return $response;
    }

    private function fromClient(Request $request): ?Identifier
    {
        $supplied = $request->headers->get('X-Request-Id');

        if (!is_string($supplied) || $supplied === '') {
            return null;
        }

        // Bound the work before touching the value: a megabyte-long header
        // should be discarded, not validated.
        if (strlen($supplied) > 36) {
            return null;
        }

        try {
            return Identifier::fromString($supplied);
        } catch (InvalidValueObject) {
            // Malformed client value is silently replaced rather than rejected.
            // Failing the request would turn a cosmetic client bug into an
            // outage for that client, and the server-generated identifier
            // serves the tracing purpose just as well.
            return null;
        }
    }
}
