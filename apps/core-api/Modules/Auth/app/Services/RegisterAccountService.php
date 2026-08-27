<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Enums\OtpPurpose;
use Modules\Auth\Events\OtpDeliveryRequested;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Events\AccountRegistered;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Services\Features\PlatformFeatures;

/**
 * Patient registration. Identity creates the pending user; Auth records the OTP.
 *
 * Listed in ApprovedCoordinators because it writes Identity and Auth tables in
 * one transaction.
 */
final class RegisterAccountService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly NationalIdProtector $protector,
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly CredentialIssuer $credentials,
        private readonly PasswordPolicy $passwords,
        private readonly PasswordHasher $hasher,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AuthenticationRateLimiter $rates,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array{name: string, phone: string, national_id: string, password: string, language: string}  $input
     */
    public function handle(array $input, ?string $ipPrefix, ?string $deviceFingerprint): OtpChallengeResult
    {
        if (! PlatformFeatures::enabled(PlatformFeatures::AUTH_REGISTRATION)) {
            throw new FeatureUnavailable;
        }

        $phone = $this->protector->phone($input['phone']);
        $nationalId = $this->protector->nationalId($input['national_id']);
        $this->passwords->assert($input['password'], $phone);

        $phoneHmac = $this->protector->phoneHmac($phone);
        $this->rates->hitOtp($phoneHmac, $ipPrefix ?? '0.0.0.0');

        $language = LanguagePreference::from($input['language']);
        $now = $this->clock->now();

        return $this->transactions->run(function (TransactionContext $tx) use ($input, $phone, $nationalId, $phoneHmac, $language, $ipPrefix, $deviceFingerprint, $now): OtpChallengeResult {
            $existing = $this->identities->findByPhoneHmac($phoneHmac);
            $userId = $existing instanceof UserAccount ? $existing->id : $this->ids->next();

            if ($existing === null) {
                $user = new UserAccount(
                    $userId,
                    $input['name'],
                    AccountType::Patient,
                    AccountStatus::PendingPhone,
                    $language,
                    $this->hasher->hash($input['password']),
                    1,
                    false,
                    false,
                );

                try {
                    $this->identities->insertUser(
                        $user,
                        $this->protector->encryptPhone($phone),
                        $phoneHmac,
                        1,
                        $now,
                    );
                    $this->identities->insertNationalId(
                        $this->ids->next(),
                        $userId,
                        $this->protector->encryptNationalId($nationalId),
                        $this->protector->nationalIdHmac($nationalId),
                        1,
                        $now,
                    );
                    $tx->recordEvent(new AccountRegistered($userId, AccountStatus::PendingPhone->value, $language->value, $now));
                    $this->audit->append($tx, 'identity.account_registered', 'user', $userId, ['reason_code' => 'registration'], $userId, 'user');
                } catch (DuplicateIdentity) {
                    $existing = $this->identities->findByPhoneHmac($phoneHmac);
                    if ($existing instanceof UserAccount) {
                        $userId = $existing->id;
                    }
                }
            }

            $this->auth->invalidateOpenOtps($phoneHmac, OtpPurpose::Registration->value, $now);

            $challengeId = $this->ids->next();
            $code = $this->credentials->otpCode();
            $ttl = (int) config('identity.otp.ttl_seconds', 300);

            $this->auth->insertOtp(
                $challengeId,
                OtpPurpose::Registration->value,
                $phoneHmac,
                $this->credentials->hashOtp($challengeId->value, OtpPurpose::Registration->value, $code),
                $this->protector->encryptSecret('otp_code', $code),
                (int) config('identity.otp.max_attempts', 5),
                $now->modify(sprintf('+%d seconds', $ttl)),
                $now,
                $ipPrefix,
                $deviceFingerprint !== null && $deviceFingerprint !== ''
                    ? $this->protector->phoneHmac($phone)
                    : null,
                $language->value,
                $this->protector->encryptPhone($phone),
                1,
            );

            $tx->recordEvent(new OtpDeliveryRequested(
                $challengeId,
                'otp:'.$challengeId->value,
                $language->value,
                $now,
            ));

            return new OtpChallengeResult($challengeId->value, 'otp_required');
        });
    }
}
