<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Telemetry;

use InvalidArgumentException;

/**
 * In-process Prometheus exposition for the alerts in
 * infra/monitoring/alerts/platform.yaml.
 *
 * Label keys are allowlisted. Patient, doctor, appointment, file, prescription,
 * and free-text values are refused (Phase 00 §7.3).
 */
final class PlatformMetrics
{
    /** @var list<string> */
    private const ALLOWED_LABELS = [
        'service', 'version', 'method', 'route', 'status', 'status_class',
        'check', 'queue', 'error_class', 'connection', 'rule', 'le',
        'result', 'actor_class', 'purpose', 'client_class', 'action_group',
        'reason_code', 'assurance_level',
    ];

    /** @var list<string> */
    private const QUERY_BUCKETS = ['0.005', '0.01', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '2.5', '5', '+Inf'];

    /** @var list<string> */
    private const FORBIDDEN_LABELS = [
        'patient_id', 'doctor_id', 'appointment_id', 'prescription_id', 'file_id', 'user_id',
    ];

    /** @var array<string, array{help: string, type: string, samples: array<string, array{labels: array<string, string>, value: float}>}> */
    private array $families = [];

    public function __construct(
        private readonly string $service = 'core-api',
        private readonly string $version = '0.0.0-dev',
    ) {
        $this->families['clinic_http_responses_total'] = [
            'help' => 'HTTP responses by method, route, and status',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_http_request_duration_seconds'] = [
            'help' => 'HTTP request duration in seconds',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_readiness_status'] = [
            'help' => '1 if the process is ready, 0 otherwise',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_dependency_status'] = [
            'help' => '1 pass, 0.5 degraded, 0 fail for a named check',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_outbox_pending_total'] = [
            'help' => 'Outbox rows in PENDING or CLAIMED',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_outbox_dead_letter_total'] = [
            'help' => 'Outbox rows in DEAD_LETTER',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_outbox_oldest_pending_age_seconds'] = [
            'help' => 'Age in seconds of the oldest pending outbox row',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_redis_errors_total'] = [
            'help' => 'Redis operation errors by connection',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_redaction_canary_total'] = [
            'help' => 'Sensitive values that reached the export assertion',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_db_connections_in_use'] = [
            'help' => 'Estimated in-use database connections',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_db_connections_limit'] = [
            'help' => 'Configured database connection limit',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_horizon_queue_depth'] = [
            'help' => 'Waiting plus delayed plus reserved jobs on a Horizon lane',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_reverb_connections'] = [
            'help' => 'Connected Reverb clients when the process exports them; otherwise 0',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_provider_failures_total'] = [
            'help' => 'Provider adapter failures by error class',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_db_query_duration_seconds_bucket'] = [
            'help' => 'Database query duration histogram buckets',
            'type' => 'histogram',
            'samples' => [],
        ];
        $this->families['clinic_db_query_duration_seconds_sum'] = [
            'help' => 'Sum of observed database query durations in seconds',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_db_query_duration_seconds_count'] = [
            'help' => 'Count of observed database queries',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_auth_attempts_total'] = [
            'help' => 'Authentication attempts by result, method, and actor class',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_otp_requests_total'] = [
            'help' => 'OTP requests by purpose and result',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_mfa_challenges_total'] = [
            'help' => 'MFA challenges by result',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_authorization_decisions_total'] = [
            'help' => 'Authorization decisions by action group, result, and reason',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_profile_claims_total'] = [
            'help' => 'Profile-claim outcomes by result and assurance level',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_otp_delivery_age_seconds'] = [
            'help' => 'Age in seconds of an OTP at delivery time',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_session_revocation_latency_seconds'] = [
            'help' => 'Seconds between session revoke commit and consumer fan-out',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_active_sessions'] = [
            'help' => 'Active sessions by client class',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_auth_latency_seconds'] = [
            'help' => 'Authentication method latency in seconds',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_audit_chain_verification_ok'] = [
            'help' => '1 if the last audit-chain verification passed, 0 if it failed. Ignore unless last_run > 0.',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_audit_chain_verification_last_run_timestamp_seconds'] = [
            'help' => 'Unix timestamp of the last audit-chain verification execution. 0 if never run.',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_audit_chain_verification_last_success_timestamp_seconds'] = [
            'help' => 'Unix timestamp of the last successful audit-chain verification. 0 if never succeeded.',
            'type' => 'gauge',
            'samples' => [],
        ];
        $this->families['clinic_audit_chain_verification_failures_total'] = [
            'help' => 'Count of failed audit-chain verification executions. Monotonic; success does not reset it.',
            'type' => 'counter',
            'samples' => [],
        ];
        $this->families['clinic_audit_chain_verification_staleness_seconds'] = [
            'help' => 'Seconds since the last audit-chain verification execution. 0 if never run.',
            'type' => 'gauge',
            'samples' => [],
        ];

        foreach (self::QUERY_BUCKETS as $le) {
            $this->add('clinic_db_query_duration_seconds_bucket', ['le' => $le], 0.0, false);
        }
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $this->add($name, $labels, $by, true);
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function set(string $name, float $value, array $labels = []): void
    {
        $this->add($name, $labels, $value, false);
    }

    public function recordHttp(string $method, string $route, int $status, float $seconds): void
    {
        $statusClass = ((int) floor($status / 100)).'xx';
        $labels = [
            'method' => strtoupper($method),
            'route' => $route,
            'status' => (string) $status,
            'status_class' => $statusClass,
        ];
        $this->increment('clinic_http_responses_total', $labels);
        $this->set('clinic_http_request_duration_seconds', $seconds, [
            'method' => strtoupper($method),
            'route' => $route,
            'status_class' => $statusClass,
        ]);
    }

    public function recordCanary(string $rule): void
    {
        $this->increment('clinic_redaction_canary_total', ['rule' => $rule]);
    }

    public function observeQuery(float $seconds): void
    {
        foreach (self::QUERY_BUCKETS as $le) {
            if ($le === '+Inf' || $seconds <= (float) $le) {
                $this->increment('clinic_db_query_duration_seconds_bucket', ['le' => $le]);
            }
        }

        $this->increment('clinic_db_query_duration_seconds_sum', [], $seconds);
        $this->increment('clinic_db_query_duration_seconds_count');
    }

    public function render(): string
    {
        $lines = [];

        foreach ($this->families as $name => $family) {
            $lines[] = '# HELP '.$name.' '.$family['help'];
            $lines[] = '# TYPE '.$name.' '.$family['type'];

            if ($family['samples'] === []) {
                $lines[] = $name.'{service="'.$this->escape($this->service).'",version="'.$this->escape($this->version).'"} 0';

                continue;
            }

            foreach ($family['samples'] as $sample) {
                $labels = $sample['labels'] + [
                    'service' => $this->service,
                    'version' => $this->version,
                ];
                $rendered = [];

                foreach ($labels as $key => $value) {
                    $rendered[] = $key.'="'.$this->escape($value).'"';
                }

                $lines[] = $name.'{'.implode(',', $rendered).'} '.$this->format($sample['value']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function add(string $name, array $labels, float $value, bool $increment): void
    {
        if (! isset($this->families[$name])) {
            throw new InvalidArgumentException('Unknown metric family.');
        }

        $this->assertLabels($labels);
        $key = json_encode($labels, JSON_THROW_ON_ERROR);

        if ($increment && isset($this->families[$name]['samples'][$key])) {
            $this->families[$name]['samples'][$key]['value'] += $value;

            return;
        }

        $this->families[$name]['samples'][$key] = [
            'labels' => $labels,
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function assertLabels(array $labels): void
    {
        foreach (array_keys($labels) as $key) {
            if (in_array($key, self::FORBIDDEN_LABELS, true)) {
                throw new InvalidArgumentException('Forbidden metric label.');
            }

            if (! in_array($key, self::ALLOWED_LABELS, true)) {
                throw new InvalidArgumentException('Metric label is not on the allowlist.');
            }
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
