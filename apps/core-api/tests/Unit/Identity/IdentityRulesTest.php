<?php

declare(strict_types=1);

use App\Modules\Access\Application\DefaultDenyAuthorizer;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Auth\Domain\Rules\PasswordPolicy;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\AccountType;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Identity\Domain\ValueObjects\AssuranceLevel;
use App\Modules\Identity\Domain\ValueObjects\LanguagePreference;
use App\Modules\Identity\Domain\ValueObjects\NationalId;
use App\Modules\Identity\Domain\ValueObjects\PhoneE164;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

describe('phone canonicalization', function () {
    it('accepts western, arabic-indic, and separated egyptian mobiles', function (string $raw) {
        $phone = PhoneE164::fromUntrusted($raw);

        expect($phone->e164())->toBe('+201012345678')
            ->and($phone->masked())->not->toContain('12345678');
    })->with([
        'western' => '01012345678',
        'plus' => '+201012345678',
        'spaces' => '010 1234 5678',
        'arabic-indic' => '٠١٠١٢٣٤٥٦٧٨',
    ]);

    it('accepts the synthetic 019 prefix only when allowed', function () {
        expect(fn () => PhoneE164::fromUntrusted('01912345678'))->toThrow(InvalidValueObject::class);

        $synthetic = PhoneE164::fromUntrusted('01912345678', true);
        expect($synthetic->e164())->toBe('+201912345678');
    });

    it('rejects a landline-shaped number without echoing it', function () {
        expect(fn () => PhoneE164::fromUntrusted('0223456789'))
            ->toThrow(InvalidValueObject::class, 'Phone number is not a valid Egyptian mobile number.');
    });
});

describe('national id canonicalization', function () {
    it('accepts arabic-indic digits and separators for a valid century-3 id', function () {
        $id = NationalId::fromUntrusted('3-00-01-01-01-0001-1');

        expect($id->canonical())->toBe('30001010100011')
            ->and($id->masked())->toStartWith('3')
            ->and($id->masked())->not->toContain('000101');
    });

    it('accepts synthetic century-9 ids only when allowed', function () {
        expect(fn () => NationalId::fromUntrusted('99999990100011'))->toThrow(InvalidValueObject::class);

        $synthetic = NationalId::fromUntrusted('99999990100011', true);
        expect($synthetic->canonical())->toBe('99999990100011');
    });

    it('rejects an impossible calendar date', function () {
        expect(fn () => NationalId::fromUntrusted('29999010100011'))->toThrow(InvalidValueObject::class);
    });
});

describe('password policy', function () {
    it('rejects short, numeric-only, and phone-containing passwords', function () {
        $policy = new PasswordPolicy;
        $phone = PhoneE164::fromUntrusted('01012345678');

        expect(fn () => $policy->assert('short'))->toThrow(InvalidValueObject::class);
        expect(fn () => $policy->assert('123456789012'))->toThrow(InvalidValueObject::class);
        expect(fn () => $policy->assert('aa1012345678zz', $phone))->toThrow(InvalidValueObject::class);
        $policy->assert('correct-horse-battery');
    });
});

describe('default-deny authorizer', function () {
    it('allows known self-service actions and denies unknown clinical ones', function () {
        $actor = new ActorContext(
            Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01'),
            AccountType::Patient,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal1Password,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );
        $authorizer = new DefaultDenyAuthorizer;

        expect($authorizer->decide($actor, Capabilities::IDENTITY_ME_READ)->allowed)->toBeTrue();
        expect($authorizer->decide($actor, 'clinical.record.read')->allowed)->toBeFalse()
            ->and($authorizer->decide($actor, 'clinical.record.read')->reasonCode)->toBe('unknown_action');
    });

    it('restricts pending users from password change', function () {
        $actor = new ActorContext(
            Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02'),
            AccountType::Patient,
            AccountStatus::PendingPhone,
            LanguagePreference::English,
            AssuranceLevel::Aal1Password,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );

        expect((new DefaultDenyAuthorizer)->decide($actor, Capabilities::PASSWORD_CHANGE)->reasonCode)
            ->toBe('pending_restricted');
    });
});
