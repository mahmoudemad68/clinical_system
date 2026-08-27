<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

/**
 * Identity assurance recorded on sessions and profile links (ADR 0011).
 */
enum AssuranceLevel: string
{
    case Aal1Password = 'aal1_password';
    case Aal2Totp = 'aal2_totp';
    case Aal2OtpPhone = 'aal2_otp_phone';
    case Ial1SelfAsserted = 'ial1_self_asserted';
    case Ial2ProofPending = 'ial2_proof_pending';
    case Ial2VerifiedLink = 'ial2_verified_link';
    case Ial3Operator = 'ial3_operator';

    public function satisfiesPrivilegedSession(): bool
    {
        return $this === self::Aal2Totp;
    }
}
