<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\AccountType;
use App\Modules\Identity\Domain\ValueObjects\LanguagePreference;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

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
    ) {}
}
