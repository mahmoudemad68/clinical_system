<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

/**
 * Why sensitive plaintext is decrypted. Maps to FieldEncryptor AAD purpose.
 *
 * OTP delivery is internal processing and is still audited (ISR-014).
 */
enum SensitiveDecryptPurpose: string
{
    case TotpVerify = 'totp_verify';
    case TotpConfirm = 'totp_confirm';
    case TotpDisable = 'totp_disable';
    case TotpReplaceConfirm = 'totp_replace_confirm';
    case TotpRecoveryCodes = 'recovery_codes_rotate';
    case TotpBootstrapConfirm = 'totp_bootstrap_confirm';
    case OtpDeliveryCode = 'otp_delivery_code';
    case OtpDeliveryDestination = 'otp_delivery_destination';
    case PhoneRecoveryNotice = 'phone_recovery_notice';
    case PushTokenDelivery = 'push_token_delivery';
    case PhoneKeyRotation = 'phone_key_rotation';
    case NationalIdKeyRotation = 'national_id_key_rotation';
    case TotpKeyRotation = 'totp_key_rotation';
    case PushTokenKeyRotation = 'push_token_key_rotation';

    /**
     * Envelope AAD / FieldEncryptor purpose. Never a secret value.
     */
    public function aadPurpose(): string
    {
        return match ($this) {
            self::TotpVerify,
            self::TotpConfirm,
            self::TotpDisable,
            self::TotpReplaceConfirm,
            self::TotpRecoveryCodes,
            self::TotpBootstrapConfirm,
            self::TotpKeyRotation => 'mfa_secret',
            self::OtpDeliveryCode => 'otp_code',
            self::OtpDeliveryDestination,
            self::PhoneRecoveryNotice,
            self::PhoneKeyRotation => 'phone',
            self::NationalIdKeyRotation => 'national_id',
            self::PushTokenDelivery,
            self::PushTokenKeyRotation => 'push_token',
        };
    }

    public function decryptClass(): SensitiveDecryptClass
    {
        return SensitiveDecryptClass::InternalProcessing;
    }
}
