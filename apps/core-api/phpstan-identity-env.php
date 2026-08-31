<?php

declare(strict_types=1);

/**
 * PHPStan boots Laravel. Identity encryption no longer requires keys at
 * construct time, but these match phpunit.xml so analysis sees the same
 * non-secret fixtures the test suite uses.
 */
$fixtures = [
    'APP_KEY' => '01234567890123456789012345678901',
    'IDENTITY_HMAC_KEY_V1' => 'test_identity_hmac_v1_not_a_secret_value!!',
    'IDENTITY_HMAC_KEY_V2' => 'test_identity_hmac_v2_not_a_secret_value!!',
    'IDENTITY_ENCRYPTION_KEY_V1' => 'test_identity_enc_v1_not_a_secret_value!!',
    'IDENTITY_ENCRYPTION_KEY_V2' => 'test_identity_enc_v2_not_a_secret_value!!',
    'AUTH_OTP_PEPPER_V1' => 'test_otp_pepper_v1_not_a_secret_value!!!!',
    'IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS' => 'true',
];

foreach ($fixtures as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
