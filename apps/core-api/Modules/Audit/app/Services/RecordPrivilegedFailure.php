<?php

declare(strict_types=1);

namespace Modules\Audit\Services;

use Closure;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Support\Identifier;

/**
 * Durable privileged-failure audit. Callers that already own a committing
 * transaction pass it; otherwise this opens its own transaction so a rolled-back
 * business denial cannot erase the row.
 */
final class RecordPrivilegedFailure
{
    public const AUTHENTICATION_FAILED = 'auth.privileged_authentication_failed';

    public const AUTHORIZATION_DENIED = 'auth.privileged_authorization_denied';

    public const BOOTSTRAP_DENIED = 'identity.bootstrap_denied';

    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function authenticationFailed(
        Identifier $userId,
        string $accountType,
        string $reasonCode,
        string $method,
        ?TransactionContext $tx = null,
    ): void {
        $this->write($tx, function (TransactionContext $context) use ($userId, $accountType, $reasonCode, $method): void {
            $this->audit->append(
                $context,
                self::AUTHENTICATION_FAILED,
                'user',
                $userId,
                [
                    'reason_code' => $reasonCode,
                    'account_type' => $accountType,
                    'method' => $method,
                ],
                $userId,
                'user',
            );
        });
    }

    public function authorizationDenied(
        Identifier $actorId,
        string $accountType,
        string $assuranceLevel,
        string $capability,
        string $reasonCode,
        Identifier $objectId,
        string $objectType,
    ): void {
        $this->write(null, function (TransactionContext $context) use (
            $actorId,
            $accountType,
            $assuranceLevel,
            $capability,
            $reasonCode,
            $objectId,
            $objectType,
        ): void {
            $this->audit->append(
                $context,
                self::AUTHORIZATION_DENIED,
                $objectType,
                $objectId,
                [
                    'reason_code' => $reasonCode,
                    'account_type' => $accountType,
                    'assurance_level' => $assuranceLevel,
                    'capability' => $capability,
                ],
                $actorId,
                'user',
            );
        });
    }

    public function bootstrapDenied(Identifier $userId, string $reasonCode): void
    {
        $this->write(null, function (TransactionContext $context) use ($userId, $reasonCode): void {
            $this->audit->append(
                $context,
                self::BOOTSTRAP_DENIED,
                'user',
                $userId,
                ['reason_code' => $reasonCode],
                $userId,
                'user',
            );
        });
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
