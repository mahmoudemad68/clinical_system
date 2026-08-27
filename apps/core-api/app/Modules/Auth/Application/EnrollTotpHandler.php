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
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;

final class EnrollTotpHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly Authorize $authorizer,
        private readonly TotpVerifier $totp,
        private readonly NationalIdProtector $protector,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @return array{factor_id: string, provisioning_uri: string}
     */
    public function handle(ActorContext $actor): array
    {
        $decision = $this->authorizer->decide($actor, Capabilities::MFA_MANAGE_SELF);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor): array {
            if ($this->auth->activeTotp($actor->userId) !== null || $this->auth->pendingTotp($actor->userId) !== null) {
                throw new InvalidValueObject('An authenticator factor is already pending or active.');
            }

            $now = $this->clock->now();
            $factorId = $this->ids->next();
            $secret = $this->totp->generateSecret();
            $this->auth->insertTotpFactor([
                'id' => $factorId->value,
                'user_id' => $actor->userId->value,
                'factor_type' => 'totp',
                'secret_ciphertext' => $this->protector->encryptSecret('mfa_secret', $secret),
                'key_version' => 1,
                'last_used_counter' => null,
                'last_used_at' => null,
                'verified_at' => null,
                'disabled_at' => null,
                'disabled_by' => null,
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->append($tx, 'auth.mfa_enroll_started', 'mfa_factor', $factorId, ['reason_code' => 'enroll'], $actor->userId, 'user');

            return [
                'factor_id' => $factorId->value,
                'provisioning_uri' => $this->totp->provisioningUri($secret, $actor->userId->value),
            ];
        });
    }
}
