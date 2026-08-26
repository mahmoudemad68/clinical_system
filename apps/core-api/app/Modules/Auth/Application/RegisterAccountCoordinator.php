<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthenticationRateLimiter;
use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use App\Modules\Auth\Domain\Events\OtpDeliveryRequested;
use App\Modules\Auth\Domain\Rules\PasswordPolicy;
use App\Modules\Auth\Domain\ValueObjects\OtpPurpose;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\Events\AccountRegistered;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\AccountType;
use App\Modules\Identity\Domain\ValueObjects\LanguagePreference;
use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\DuplicateIdentity;
use App\Modules\Platform\Domain\Exceptions\FeatureUnavailable;

/**
 * Patient registration. Identity creates the pending user; Auth records the OTP.
 *
 * Listed in ApprovedCoordinators because it writes Identity and Auth tables in
 * one transaction.
 */
final class RegisterAccountCoordinator
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
