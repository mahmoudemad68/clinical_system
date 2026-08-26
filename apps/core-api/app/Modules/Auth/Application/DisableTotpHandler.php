<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\TotpVerifier;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

final class DisableTotpHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly Authorize $authorizer,
        private readonly TotpVerifier $totp,
        private readonly NationalIdProtector $protector,
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
            $secret = $this->protector->decryptSecret('mfa_secret', (string) $factor->secret_ciphertext);
            $this->audit->append($tx, 'auth.sensitive_decrypt', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), [
                'reason_code' => 'totp_disable',
                'purpose' => 'mfa_secret',
            ], $actor->userId, 'user');
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
