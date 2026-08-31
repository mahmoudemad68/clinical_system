<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Auth\Enums\RecoveryNoticeKind;

/**
 * Lock-screen-safe recovery copy. No OTP, password, proof, or identifier.
 */
final class RecoveryOldChannelCopy
{
    public static function body(string $noticeKind, string $locale): string
    {
        $applied = $noticeKind === RecoveryNoticeKind::Applied->value;

        if ($locale === 'ar') {
            return $applied
                ? 'اكتملت استعادة الحساب. تواصل مع الدعم إذا لم تكن أنت.'
                : 'تم استلام طلب استعادة لحسابك. تواصل مع الدعم إذا لم تكن أنت.';
        }

        return $applied
            ? 'Account recovery was completed. Contact support if this was unexpected.'
            : 'A recovery request was received for your account. Contact support if this was unexpected.';
    }
}
