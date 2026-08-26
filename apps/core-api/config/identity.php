<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Identity, authentication, and access configuration
|--------------------------------------------------------------------------
|
| Fail-closed defaults. Registration, profile claim, and recovery never enable
| themselves from an empty environment. Production ignores enablement flags
| (ADR 0011, ADR 0013).
|
*/

return [

    'allow_synthetic_national_ids' => (bool) env('IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS', false),

    'registration_enabled' => (bool) env('FEATURE_AUTH_REGISTRATION', false),
    'profile_claim_enabled' => (bool) env('FEATURE_IDENTITY_PROFILE_CLAIM', false),
    'recovery_enabled' => (bool) env('FEATURE_AUTH_RECOVERY', false),

    'hmac' => [
        'current_version' => (int) env('IDENTITY_HMAC_VERSION', 1),
        'min_key_length' => 32,
        'keys' => [
            1 => (string) env('IDENTITY_HMAC_KEY_V1', ''),
            2 => (string) env('IDENTITY_HMAC_KEY_V2', ''),
        ],
    ],

    'encryption' => [
        'current_version' => (int) env('IDENTITY_ENCRYPTION_VERSION', 1),
        'min_key_length' => 32,
        'keys' => [
            1 => (string) env('IDENTITY_ENCRYPTION_KEY_V1', ''),
            2 => (string) env('IDENTITY_ENCRYPTION_KEY_V2', ''),
        ],
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => (int) env('AUTH_OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
        'resend_seconds' => (int) env('AUTH_OTP_RESEND_SECONDS', 60),
        'pepper_version' => (int) env('AUTH_OTP_PEPPER_VERSION', 1),
        'peppers' => [
            1 => (string) env('AUTH_OTP_PEPPER_V1', ''),
        ],
        'global_hourly_budget' => (int) env('AUTH_OTP_GLOBAL_HOURLY_BUDGET', 200),
    ],

    'password' => [
        'min_length' => 12,
        'max_length' => 128,
    ],

    'session' => [
        'device_access_ttl_seconds' => (int) env('AUTH_DEVICE_ACCESS_TTL_SECONDS', 900),
        'device_refresh_ttl_seconds' => (int) env('AUTH_DEVICE_REFRESH_TTL_SECONDS', 2592000),
        'admin_idle_seconds' => (int) env('AUTH_ADMIN_IDLE_SECONDS', 1800),
        'admin_absolute_seconds' => (int) env('AUTH_ADMIN_ABSOLUTE_SECONDS', 28800),
        'revocation_cache_ttl_seconds' => (int) env('AUTH_REVOCATION_CACHE_TTL_SECONDS', 30),
        'revocation_slo_seconds' => (int) env('AUTH_REVOCATION_SLO_SECONDS', 5),
    ],

    'mfa' => [
        'digits' => 6,
        'period' => 30,
        'skew_periods' => 1,
        'challenge_ttl_seconds' => (int) env('AUTH_MFA_CHALLENGE_TTL_SECONDS', 300),
        'recovery_code_count' => 8,
    ],

    'rate_limits' => [
        'login_per_subject_per_minute' => (int) env('AUTH_LOGIN_PER_SUBJECT_PER_MINUTE', 5),
        'login_per_ip_per_minute' => (int) env('AUTH_LOGIN_PER_IP_PER_MINUTE', 20),
        'otp_per_subject_per_hour' => (int) env('AUTH_OTP_PER_SUBJECT_PER_HOUR', 5),
        'otp_per_ip_per_hour' => (int) env('AUTH_OTP_PER_IP_PER_HOUR', 20),
        'recovery_per_subject_per_hour' => (int) env('AUTH_RECOVERY_PER_SUBJECT_PER_HOUR', 3),
        'refresh_per_device_per_minute' => (int) env('AUTH_REFRESH_PER_DEVICE_PER_MINUTE', 30),
        'refresh_per_ip_per_minute' => (int) env('AUTH_REFRESH_PER_IP_PER_MINUTE', 60),
        'mfa_per_challenge_per_minute' => (int) env('AUTH_MFA_PER_CHALLENGE_PER_MINUTE', 10),
    ],

    'recovery' => [
        'cooling_off_seconds' => (int) env('IDENTITY_RECOVERY_COOLING_OFF_SECONDS', 86400),
    ],

    'retention' => [
        'otp_row_days' => (int) env('IDENTITY_OTP_ROW_DAYS', 30),
        'revoked_session_days' => (int) env('IDENTITY_REVOKED_SESSION_DAYS', 90),
    ],

    'refresh' => [
        'replay_grace_seconds' => (int) env('AUTH_REFRESH_REPLAY_GRACE_SECONDS', 60),
    ],

    'trusted_proxies' => array_values(array_filter(array_map(
        static fn (string $hop): string => trim($hop),
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),

    'bootstrap' => [
        'enabled' => (bool) env('IDENTITY_BOOTSTRAP_ENABLED', false),
    ],
];
