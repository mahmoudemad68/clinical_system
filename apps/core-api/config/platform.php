<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform shared-kernel configuration
|--------------------------------------------------------------------------
|
| Configuration for the Platform module: request bounds, idempotency,
| outbox behaviour, the AI service boundary, telemetry redaction, and the
| server-owned feature flags.
|
| Every value is read from the environment with a safe default. "Safe" here
| means fail-closed: an unset flag is off, an unset AI requirement is
| "optional", and an unset bound is the tighter one. A missing environment
| variable must never widen what the process will accept.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Request bounds
    |--------------------------------------------------------------------------
    |
    | Strict request and content-size limits are a mandatory Phase 00 control.
    | The gateway enforces a coarse limit; these are the application-side
    | backstop, because the gateway is not the only path into the process.
    |
    | max_json_depth matters independently of byte size: a deeply nested
    | document is small on the wire and expensive to parse.
    |
    */
    'request' => [
        'max_body_bytes' => (int) env('REQUEST_MAX_BODY_BYTES', 1_048_576),
        'max_json_depth' => (int) env('REQUEST_MAX_JSON_DEPTH', 32),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Retention must exceed the maximum client retry and offline window for the
    | operation, or a legitimate offline retry creates a second effect. The
    | doctor desktop local outbox makes this concrete: a device offline for two
    | days will replay its queue on reconnect.
    |
    */
    'idempotency' => [
        'retention_hours' => (int) env('IDEMPOTENCY_RETENTION_HOURS', 72),
        'processing_stale_after_seconds' => (int) env('IDEMPOTENCY_STALE_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox
    |--------------------------------------------------------------------------
    |
    | Retry is capped, jittered, and distinguishes permanent from transient
    | failure. Exhausted rows move to an operator-visible dead-letter state and
    | are never silently discarded (ADR 0004).
    |
    */
    'outbox' => [
        'claim_batch_size' => (int) env('OUTBOX_CLAIM_BATCH_SIZE', 100),
        'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 60),
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
        'retention_days' => (int) env('OUTBOX_RETENTION_DAYS', 7),
        'base_backoff_seconds' => (int) env('OUTBOX_BASE_BACKOFF_SECONDS', 2),
        'max_backoff_seconds' => (int) env('OUTBOX_MAX_BACKOFF_SECONDS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI service boundary
    |--------------------------------------------------------------------------
    |
    | The AI service is optional for core readiness by default. Making it
    | required is a deliberate per-deployment decision, never a default, because
    | an AI outage is not a core outage (plan.md section 141).
    |
    */
    'ai' => [
        'base_url' => (string) env('AI_SERVICE_BASE_URL', ''),
        'timeout_ms' => (int) env('AI_SERVICE_TIMEOUT_MS', 2000),
        'required_for_readiness' => (bool) env('AI_SERVICE_REQUIRED_FOR_READINESS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry redaction
    |--------------------------------------------------------------------------
    |
    | Redaction runs before export in every environment. Disabling it is not a
    | supported configuration; the flag exists so a test can assert it is on.
    |
    | In strict mode a leaked canary raises instead of being silently dropped,
    | so a redaction gap fails a test rather than reaching a log. Strict mode is
    | off in production: there, failing closed on the log path would turn a
    | redaction miss into an outage.
    |
    */
    'telemetry' => [
        'redaction_enabled' => (bool) env('TELEMETRY_REDACTION_ENABLED', true),
        'redaction_strict' => (bool) env('TELEMETRY_REDACTION_STRICT', false),
        'otel_enabled' => (bool) env('OTEL_ENABLED', false),
        'otlp_endpoint' => (string) env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Server-owned, environment-aware, audited, and fail closed for risky
    | features. A client flag never activates a server capability.
    |
    | The entries below the diagnostics slice are the V1 exclusions from
    | plan.md section 171. They exist as disabled metadata only, so a
    | "coming soon" surface needs no hack — and so that enabling one is a
    | visible, reviewable change rather than new code.
    |
    */
    'features' => [

        // Phase 00 foundation slice. Fails closed and is refused outside
        // local/development by the middleware regardless of this value.
        'diagnostics_slice' => (bool) env('FEATURE_PLATFORM_DIAGNOSTICS_SLICE', false),

        // V1 exclusions. Do not enable without the owning phase and an ADR.
        'online_payments' => (bool) env('FEATURE_ONLINE_PAYMENTS', false),
        'emergency_chat' => (bool) env('FEATURE_EMERGENCY_CHAT', false),
        'drug_alternatives' => (bool) env('FEATURE_DRUG_ALTERNATIVES', false),
        'branch_transfers' => (bool) env('FEATURE_BRANCH_TRANSFERS', false),
        'patient_adherence' => (bool) env('FEATURE_PATIENT_ADHERENCE', false),
        'medical_imaging_ai' => (bool) env('FEATURE_MEDICAL_IMAGING_AI', false),
        'supplier_api_integration' => (bool) env('FEATURE_SUPPLIER_API_INTEGRATION', false),
        'multi_country' => (bool) env('FEATURE_MULTI_COUNTRY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments in which the diagnostics slice may run at all
    |--------------------------------------------------------------------------
    |
    | Belt and braces with the feature flag. The slice writes synthetic rows and
    | must not exist in staging or production even if a flag is mis-set.
    |
    */
    'diagnostics_environments' => ['local', 'development', 'testing'],

    'diagnostics_slice_token' => (string) env('DIAGNOSTICS_SLICE_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Firebase
    |--------------------------------------------------------------------------
    |
    | Push delivery uses kreait/laravel-firebase behind SendPush. Empty
    | credentials keep the adapter fail-closed (DisabledSendPush). Inbox
    | persistence is independent and uses Laravel Database Notifications.
    |
    */
    'firebase' => [
        'credentials' => (string) env('FIREBASE_CREDENTIALS', env('GOOGLE_APPLICATION_CREDENTIALS', '')),
    ],

];
