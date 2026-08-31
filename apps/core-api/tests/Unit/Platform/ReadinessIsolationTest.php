<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Modules\Platform\Services\Health\CheckStatus;
use Modules\Platform\Services\Health\DependencyCheck;
use Modules\Platform\Services\Health\ReadinessProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gate G-02-04: an AI or Qdrant failure must not make Laravel core unready.
 *
 * `plan.md` section 141 states AI availability is measured separately and an AI
 * outage is not a core outage. ADR 0001 makes that a structural property rather
 * than an operational hope.
 *
 * These run as unit tests because ReadinessProbe holds no framework type: the
 * checks are ports. That is the payoff from inverting them — the isolation
 * guarantee is provable in milliseconds without a container or a network, so it
 * runs on every commit instead of only in a nightly system test.
 */
final class ReadinessIsolationTest extends TestCase
{
    #[Test]
    public function core_stays_ready_when_the_optional_ai_dependency_is_down(): void
    {
        $probe = new ReadinessProbe(
            [
                self::check('configuration', critical: true, status: CheckStatus::Pass),
                self::check('postgresql', critical: true, status: CheckStatus::Pass),
                self::check('redis_cache', critical: true, status: CheckStatus::Pass),
                // The AI service is down.
                self::check('ai_service', critical: false, status: CheckStatus::Degraded),
            ],
            '0.1.0',
        );

        $result = $probe->evaluate();

        $this->assertTrue($result->ready, 'An AI outage must not unseat core readiness.');
        $this->assertSame(200, $result->httpStatus());
        $this->assertSame('ready', $result->toArray()['status']);

        // The outage is still visible to operators. Reporting "ready" while
        // hiding the degradation would trade one failure mode for a worse one.
        $ai = array_values(array_filter(
            $result->toArray()['checks'],
            static fn (array $c): bool => $c['name'] === 'ai_service',
        ))[0];

        $this->assertSame('degraded', $ai['status']);
        $this->assertFalse($ai['critical']);
    }

    #[Test]
    public function core_stays_ready_even_when_an_optional_dependency_hard_fails(): void
    {
        // A non-critical check reporting Fail rather than Degraded must still
        // not unseat readiness. Only criticality decides.
        $probe = new ReadinessProbe(
            [
                self::check('postgresql', critical: true, status: CheckStatus::Pass),
                self::check('qdrant', critical: false, status: CheckStatus::Fail),
            ],
            '0.1.0',
        );

        $this->assertTrue($probe->evaluate()->ready);
    }

    #[Test]
    public function core_becomes_unready_when_a_critical_dependency_fails(): void
    {
        // The other half of the gate. A probe that always answers "ready" would
        // pass every test above and be worthless, so the negative case is the
        // one that proves the oracle works.
        $probe = new ReadinessProbe(
            [
                self::check('configuration', critical: true, status: CheckStatus::Pass),
                self::check('postgresql', critical: true, status: CheckStatus::Fail),
                self::check('ai_service', critical: false, status: CheckStatus::Pass),
            ],
            '0.1.0',
        );

        $result = $probe->evaluate();

        $this->assertFalse($result->ready);
        $this->assertSame(503, $result->httpStatus());
        $this->assertSame('not_ready', $result->toArray()['status']);
    }

    #[Test]
    public function a_degraded_critical_dependency_does_not_unseat_readiness(): void
    {
        // Degraded means reduced, not broken. Treating it as failure would make
        // every transient slowdown an outage.
        $probe = new ReadinessProbe(
            [self::check('postgresql', critical: true, status: CheckStatus::Degraded)],
            '0.1.0',
        );

        $this->assertTrue($probe->evaluate()->ready);
    }

    #[Test]
    public function ai_failure_becomes_critical_only_when_explicitly_configured(): void
    {
        // A deployment may legitimately decide AI is required, so the split is
        // configuration rather than a hard-coded assumption.
        $probe = new ReadinessProbe(
            [self::check('ai_service', critical: true, status: CheckStatus::Fail)],
            '0.1.0',
        );

        $this->assertFalse($probe->evaluate()->ready);
    }

    #[Test]
    public function check_results_carry_bounded_names_safe_for_metric_labels(): void
    {
        $probe = new ReadinessProbe(
            [
                self::check('postgresql', critical: true, status: CheckStatus::Pass),
                self::check('redis_cache', critical: true, status: CheckStatus::Pass),
            ],
            '0.1.0',
        );

        foreach ($probe->evaluate()->toArray()['checks'] as $check) {
            // The phase file forbids unbounded metric-label cardinality. Check
            // names are drawn from a small fixed set, so this asserts shape
            // rather than a specific value.
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,31}$/', $check['name']);
        }
    }

    private static function check(string $name, bool $critical, CheckStatus $status): DependencyCheck
    {
        return new class($name, $critical, $status) implements DependencyCheck
        {
            public function __construct(
                private readonly string $name,
                private readonly bool $critical,
                private readonly CheckStatus $status,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function isCritical(): bool
            {
                return $this->critical;
            }

            public function run(): CheckStatus
            {
                return $this->status;
            }
        };
    }
}
