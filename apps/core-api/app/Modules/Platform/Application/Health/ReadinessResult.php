<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Health;

/**
 * Aggregate readiness for this process.
 *
 * Shape matches ReadinessResult in packages/contracts/openapi/openapi.yaml.
 * Deliberately free of hostnames, connection strings, dependency versions, and
 * error text: the probe endpoint is reachable by anything that can reach the
 * port, so it must not double as a reconnaissance surface.
 */
final readonly class ReadinessResult
{
    /**
     * @param  list<ReadinessCheck>  $checks
     */
    public function __construct(
        public bool $ready,
        public string $service,
        public string $version,
        public array $checks,
    ) {}

    public function httpStatus(): int
    {
        return $this->ready ? 200 : 503;
    }

    /**
     * @return array{status: string, service: string, version: string, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->ready ? 'ready' : 'not_ready',
            'service' => $this->service,
            'version' => $this->version,
            'checks' => array_map(static fn (ReadinessCheck $c): array => $c->toArray(), $this->checks),
        ];
    }
}
