<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use DateTimeImmutable;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\NationalId;
use Modules\Identity\Support\PhoneE164;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\DuplicateIdentity;

/**
 * Privileged provisioning of a doctor applicant account. Identity owns the
 * user and National-ID rows. Callers must wrap this in their transaction.
 */
final class ProvisionDoctorApplicantAccount
{
    public function __construct(
        private readonly UserDirectory $identities,
        private readonly NationalIdProtector $protector,
        private readonly PasswordPolicy $passwords,
        private readonly PasswordHasher $hasher,
        private readonly IdentityGenerator $ids,
    ) {}

    public function create(
        string $name,
        PhoneE164 $phone,
        NationalId $nationalId,
        string $password,
        DateTimeImmutable $now,
        LanguagePreference $language = LanguagePreference::Arabic,
    ): UserAccount {
        $this->passwords->assert($password, $phone);

        if ($this->identities->findByPhoneHmacs($this->protector->phoneLookupHmacs($phone)) instanceof UserAccount) {
            throw new DuplicateIdentity;
        }

        if ($this->identities->nationalIdHmacsTaken($this->protector->nationalIdLookupHmacs($nationalId))) {
            throw new DuplicateIdentity;
        }

        $user = new UserAccount(
            $this->ids->next(),
            $name,
            AccountType::Doctor,
            AccountStatus::Active,
            $language,
            $this->hasher->hash($password),
            1,
            true,
            false,
            false,
        );

        $this->identities->insertUser(
            $user,
            $this->protector->encryptPhone($phone),
            $this->protector->phoneHmac($phone),
            $this->protector->encryptionVersion(),
            $this->protector->hmacVersion(),
            $now,
        );
        $this->identities->insertNationalId(
            $this->ids->next(),
            $user->id,
            $this->protector->encryptNationalId($nationalId),
            $this->protector->nationalIdHmac($nationalId),
            $this->protector->encryptionVersion(),
            $this->protector->hmacVersion(),
            $now,
        );

        return $user;
    }
}
