<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Server-derived actor snapshot for policies (Phase 01 Access contract).
 *
 * Built only from authoritative storage. Clients never supply these fields.
 */
final readonly class ActorContext
{
    /**
     * @param  list<string>  $profileLinkIds
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public Identifier $userId,
        public AccountType $accountType,
        public AccountStatus $status,
        public LanguagePreference $language,
        public AssuranceLevel $assuranceLevel,
        public int $credentialVersion,
        public ?Identifier $deviceId,
        public ?Identifier $sessionId,
        public array $profileLinkIds,
        public array $capabilities,
    ) {}

    public function isPending(): bool
    {
        return $this->status === AccountStatus::PendingPhone;
    }
}
