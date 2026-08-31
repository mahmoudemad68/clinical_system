<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Enums\OtpPurpose;
use Modules\Auth\Events\OtpDeliveryRequested;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Services\Features\PlatformFeatures;

final class RequestOtpService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly NationalIdProtector $protector,
        private readonly UserDirectory $identities,
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
        $lookupHmacs = $this->protector->phoneLookupHmacs($parsed);
        $this->rates->hitOtp($hmac, $ipPrefix ?? '0.0.0.0');
        $now = $this->clock->now();

        return $this->transactions->run(function (TransactionContext $tx) use ($parsed, $hmac, $lookupHmacs, $otpPurpose, $locale, $ipPrefix, $now): OtpChallengeResult {
            $existing = $this->identities->findByPhoneHmacs($lookupHmacs);
            $subjectHmac = $existing !== null
                ? ($this->identities->phoneLookupHmac($existing->id) ?? $hmac)
                : $hmac;

            $this->auth->invalidateOpenOtps($lookupHmacs, $otpPurpose->value, $now);
            $challengeId = $this->ids->next();
            $code = $this->credentials->otpCode();
            $ttl = (int) config('identity.otp.ttl_seconds', 300);

            $this->auth->insertOtp(
                $challengeId,
                $otpPurpose->value,
                $subjectHmac,
                $this->credentials->hashOtp($challengeId->value, $otpPurpose->value, $code),
                $this->protector->encryptSecret('otp_code', $code),
                (int) config('identity.otp.max_attempts', 5),
                $now->modify(sprintf('+%d seconds', $ttl)),
                $now,
                $ipPrefix,
                null,
                $locale,
                $this->protector->encryptPhone($parsed),
                $this->protector->encryptionVersion(),
            );

            $tx->recordEvent(new OtpDeliveryRequested($challengeId, 'otp:'.$challengeId->value, $locale, $now));

            return new OtpChallengeResult($challengeId->value, 'otp_required');
        });
    }
}
