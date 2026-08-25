<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Health\CheckStatus;
use App\Modules\Platform\Application\Health\ReadinessProbe;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Http\Responses\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client-facing platform metadata and coarse health.
 *
 * Separate from OperationalController for a reason: this speaks the public
 * envelope, negotiates language, and answers a different question. The
 * orchestrator asks "should I route traffic here"; a patient's phone asks "is
 * the service working, and can you tell me in Arabic".
 *
 * Component status is reported at the coarsest granularity that is still
 * useful. It never exposes hostnames, dependency versions, connection details,
 * or error text.
 */
final class PlatformHealthController
{
    public function __construct(
        private readonly ReadinessProbe $readiness,
        private readonly Clock $clock,
        private readonly string $version,
        private readonly string $environment,
        private readonly ?string $commit,
        private readonly ?string $builtAt,
    ) {}

    /**
     * GET /api/v1/meta/version
     */
    public function version(Request $request): JsonResponse
    {
        return Envelope::ok(
            [
                'service' => 'core-api',
                'version' => $this->version,
                'api_version' => 'v1',
                'commit' => $this->commit,
                'built_at' => $this->builtAt,
                'environment' => $this->environment,
            ],
            $this->requestId($request),
            ['locale' => app()->getLocale()],
        );
    }

    /**
     * GET /api/v1/health
     *
     * The Phase 00 end-to-end gate: each of the four clients starts against the
     * stack and displays core health and version in Arabic and English.
     */
    public function health(Request $request): JsonResponse
    {
        $result = $this->readiness->evaluate();

        $components = [
            'core' => 'operational',
            'realtime' => 'operational',
            'ai' => 'operational',
        ];

        foreach ($result->checks as $check) {
            $component = match ($check->name) {
                'postgresql', 'configuration', 'redis_cache' => 'core',
                'redis_queue' => 'realtime',
                'ai_service' => 'ai',
                default => null,
            };

            if ($component === null) {
                continue;
            }

            $status = match ($check->status) {
                CheckStatus::Pass => 'operational',
                CheckStatus::Degraded => 'degraded',
                CheckStatus::Fail => 'unavailable',
            };

            // Worst status wins per component: one failing check must not be
            // masked by a passing one that happens to be evaluated later.
            if ($this->severity($status) > $this->severity($components[$component])) {
                $components[$component] = $status;
            }
        }

        // Overall status is driven by the core only. An AI outage shows as
        // degraded, never unavailable, because the platform still works
        // (plan.md section 141).
        $overall = match (true) {
            $components['core'] === 'unavailable' => 'unavailable',
            $components['core'] === 'degraded' => 'degraded',
            $components['realtime'] !== 'operational' => 'degraded',
            $components['ai'] !== 'operational' => 'degraded',
            default => 'operational',
        };

        return Envelope::ok(
            [
                'status' => $overall,
                'message' => trans("health.status.{$overall}"),
                'components' => $components,
                'version' => $this->version,
                'server_time' => $this->clock->now()->format(DATE_RFC3339),
            ],
            $this->requestId($request),
            ['locale' => app()->getLocale()],
        );
    }

    private function severity(string $status): int
    {
        return match ($status) {
            'operational' => 0,
            'degraded' => 1,
            'unavailable' => 2,
            default => 0,
        };
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
