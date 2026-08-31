<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Closure;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Enums\SensitiveDecryptPurpose;
use Modules\Platform\Contracts\CorrelationScope;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Support\Identifier;

/**
 * Enforced plaintext-decrypt boundary for identity secrets (ADR 0013).
 *
 * Callers receive plaintext only after a secret-free auth.sensitive_decrypt
 * row is appended. AES-GCM itself is not audited recursively.
 */
final class AuditedSensitiveDecryptor
{
    public function __construct(
        private readonly FieldEncryptor $encryptor,
        private readonly AppendAuditEvent $audit,
        private readonly TransactionRunner $transactions,
        private readonly CorrelationScope $correlation,
    ) {}

    public function decrypt(
        SensitiveDecryptPurpose $purpose,
        string $envelope,
        string $objectType,
        Identifier $objectId,
        ?Identifier $actorId,
        ?string $actorType,
        ?TransactionContext $tx = null,
    ): string {
        $keyVersion = $this->encryptor->envelopeVersion($envelope);
        $plain = $this->encryptor->decrypt($purpose->aadPurpose(), $envelope);

        $this->write($tx, function (TransactionContext $context) use ($purpose, $objectType, $objectId, $actorId, $actorType, $keyVersion): void {
            $this->audit->append(
                $context,
                'auth.sensitive_decrypt',
                $objectType,
                $objectId,
                [
                    'reason_code' => $purpose->value,
                    'purpose' => $purpose->aadPurpose(),
                    'decrypt_class' => $purpose->decryptClass()->value,
                    'key_version' => $keyVersion,
                    'correlation_id' => $this->correlation->current()->value,
                ],
                $actorId,
                $actorType,
            );
        });

        return $plain;
    }

    /**
     * @param  Closure(TransactionContext): void  $write
     */
    private function write(?TransactionContext $tx, Closure $write): void
    {
        if ($tx !== null) {
            $write($tx);

            return;
        }

        $this->transactions->run($write);
    }
}
