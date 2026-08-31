<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Platform\Support\Identifier;

final readonly class UserAccount
{
    public function __construct(
        public Identifier $id,
        public string $name,
        public AccountType $accountType,
        public AccountStatus $status,
        public LanguagePreference $language,
        public string $passwordHash,
        public int $credentialVersion,
        public bool $phoneVerified,
        public bool $bootstrapExempt,
        public bool $passwordMustChange = false,
    ) {}
}
