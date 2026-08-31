<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Platform\Support\Identifier;

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
        public bool $passwordMustChange = false,
    ) {}

    public function isPending(): bool
    {
        return $this->status === AccountStatus::PendingPhone;
    }

    public function requiresPasswordChange(): bool
    {
        return $this->passwordMustChange;
    }
}
