<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Enums\SensitiveDecryptPurpose;
use Modules\Identity\Services\AuditedSensitiveDecryptor;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

final class DisableTotpService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly Authorize $authorizer,
        private readonly TotpVerifier $totp,
        private readonly AuditedSensitiveDecryptor $decryptor,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function handle(ActorContext $actor, string $code): void
    {
        $decision = $this->authorizer->decide($actor, Capabilities::MFA_MANAGE_SELF);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $this->transactions->run(function (TransactionContext $tx) use ($actor, $code): void {
            $factor = $this->auth->activeTotp($actor->userId);
            if ($factor === null) {
                throw new InvalidValueObject('No active authenticator factor exists.');
            }

            $now = $this->clock->now();
            $secret = $this->decryptor->decrypt(
                SensitiveDecryptPurpose::TotpDisable,
                (string) $factor->secret_ciphertext,
                'mfa_factor',
                Identifier::fromTrusted((string) $factor->id),
                $actor->userId,
                'user',
                $tx,
            );
            $result = $this->totp->verify(
                $secret,
                $code,
                $now,
                $factor->last_used_counter !== null ? (int) $factor->last_used_counter : null,
            );
            if (! $result->valid) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            $this->auth->disableTotpFactor(
                Identifier::fromTrusted((string) $factor->id),
                $actor->userId,
                $now,
            );
            $this->audit->append($tx, 'auth.mfa_disabled', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), ['reason_code' => 'disable'], $actor->userId, 'user');
        });
    }
}
