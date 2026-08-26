<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\ValueObjects\ClientClass;
use App\Modules\Auth\Domain\ValueObjects\OtpPurpose;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\Events\PhoneVerified;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class VerifyOtpHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly CredentialIssuer $credentials,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly IssueAuthenticatedSession $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $challengeId, string $code, string $clientClass, string $platform, string $deviceLabel): array
    {
        $id = Identifier::fromString($challengeId);

        $result = $this->transactions->run(function (TransactionContext $tx) use ($id, $code, $clientClass, $platform, $deviceLabel): array {
            $row = $this->auth->lockOtp($id);

            if ($row === null) {
                return ['denied' => true];
            }

            $now = $this->clock->now();

            if ($row->consumed_at !== null || $row->invalidated_at !== null || new DateTimeImmutable((string) $row->expires_at) <= $now) {
                return ['denied' => true];
            }

            $attempts = (int) $row->attempts + 1;
            $this->auth->incrementOtpAttempts($id, $attempts);

            $expected = (string) $row->code_hash;
            $actual = $this->credentials->hashOtp((string) $row->id, (string) $row->purpose, $code);

            if (! hash_equals($expected, $actual) || $attempts > (int) $row->max_attempts) {
                return ['denied' => true];
            }

            $purpose = (string) $row->purpose;

            if ($purpose === OtpPurpose::Recovery->value) {
                return [
                    'denied' => false,
                    'status' => 'recovery_verified',
                    'challenge_id' => $id->value,
                ];
            }

            $this->auth->consumeOtp($id, $now);

            $user = $this->identities->findByPhoneHmac((string) $row->subject_lookup_hmac);

            if ($user === null) {
                return ['denied' => true];
            }

            $class = ClientClass::from($clientClass);
            if (! $class->compatibleWith($user->accountType->value)) {
                return ['denied' => true];
            }

            if ($purpose === OtpPurpose::Registration->value) {
                // An already-active account must not be authenticated by a
                // registration OTP (account-takeover via reused phone).
                if ($user->status === AccountStatus::Active || $user->phoneVerified) {
                    return [
                        'denied' => false,
                        'status' => 'otp_required',
                        'challenge_id' => $id->value,
                    ];
                }

                $this->identities->markPhoneVerified($user->id, $now);
                $tx->recordEvent(new PhoneVerified($user->id, $now));
                $this->audit->append($tx, 'identity.phone_verified', 'user', $user->id, ['reason_code' => 'otp'], $user->id, 'user');
                $user = $this->identities->findById($user->id) ?? $user;

                return ['denied' => false] + $this->sessions->issue($tx, $user, $clientClass, $platform, $deviceLabel, $now);
            }

            return [
                'denied' => false,
                'status' => 'otp_required',
                'challenge_id' => $id->value,
            ];
        });

        if (($result['denied'] ?? false) === true) {
            throw new InvalidValueObject('The verification code is invalid or expired.');
        }

        unset($result['denied']);

        return $result;
    }
}
