<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthenticationRateLimiter;
use App\Modules\Auth\Domain\Events\OtpDeliveryRequested;
use App\Modules\Auth\Domain\ValueObjects\OtpPurpose;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\FeatureUnavailable;

final class RequestOtpHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly NationalIdProtector $protector,
        private readonly AuthDirectory $auth,
        private readonly CredentialIssuer $credentials,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AuthenticationRateLimiter $rates,
    ) {}

    public function handle(string $phone, string $purpose, string $locale, ?string $ipPrefix): OtpChallengeResult
    {
        $otpPurpose = OtpPurpose::from($purpose);

        if ($otpPurpose === OtpPurpose::Recovery && ! PlatformFeatures::enabled(PlatformFeatures::AUTH_RECOVERY)) {
            throw new FeatureUnavailable;
        }

        if ($otpPurpose === OtpPurpose::ProfileClaim && ! PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM)) {
            throw new FeatureUnavailable;
        }

        $parsed = $this->protector->phone($phone);
        $hmac = $this->protector->phoneHmac($parsed);
        $this->rates->hitOtp($hmac, $ipPrefix ?? '0.0.0.0');
        $now = $this->clock->now();

        return $this->transactions->run(function (TransactionContext $tx) use ($parsed, $hmac, $otpPurpose, $locale, $ipPrefix, $now): OtpChallengeResult {
            $this->auth->invalidateOpenOtps($hmac, $otpPurpose->value, $now);
            $challengeId = $this->ids->next();
            $code = $this->credentials->otpCode();
            $ttl = (int) config('identity.otp.ttl_seconds', 300);

            $this->auth->insertOtp(
                $challengeId,
                $otpPurpose->value,
                $hmac,
                $this->credentials->hashOtp($challengeId->value, $otpPurpose->value, $code),
                $this->protector->encryptSecret('otp_code', $code),
                (int) config('identity.otp.max_attempts', 5),
                $now->modify(sprintf('+%d seconds', $ttl)),
                $now,
                $ipPrefix,
                null,
                $locale,
                $this->protector->encryptPhone($parsed),
                1,
            );

            $tx->recordEvent(new OtpDeliveryRequested($challengeId, 'otp:'.$challengeId->value, $locale, $now));

            return new OtpChallengeResult($challengeId->value, 'otp_required');
        });
    }
}
