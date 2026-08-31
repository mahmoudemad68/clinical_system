<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantContextualAccess;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Contracts\RevokeContextualAccess;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Time\FrozenClock;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function grantValiditySubjectActor(Identifier $userId): ActorContext
{
    return new ActorContext(
        $userId,
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
}

function grantValidityOperatorActor(Identifier $userId): ActorContext
{
    return new ActorContext(
        $userId,
        AccountType::Admin,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal2Totp,
        1,
        null,
        $userId,
        [],
        Capabilities::forActor('admin', true),
    );
}

function freezeGrantValidityClock(DateTimeImmutable $now): FrozenClock
{
    $clock = new FrozenClock($now);
    app()->instance(Clock::class, $clock);
    app()->forgetInstance(GrantStore::class);
    app()->forgetInstance(Authorize::class);

    return $clock;
}

/**
 * @return array{
 *     now: DateTimeImmutable,
 *     subject: Identifier,
 *     actor: ActorContext,
 *     initiator: ActorContext,
 *     resourceA: Identifier,
 *     resourceB: Identifier,
 *     context: Identifier
 * }
 */
function arrangeGrantValidityActors(DateTimeImmutable $now): array
{
    freezeGrantValidityClock($now);
    $ids = app(IdentityGenerator::class);
    $user = User::factory()->create();
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);
    $subject = Identifier::fromTrusted((string) $user->id);

    return [
        'now' => $now,
        'subject' => $subject,
        'actor' => grantValiditySubjectActor($subject),
        'initiator' => grantValidityOperatorActor(Identifier::fromTrusted((string) $admin->id)),
        'resourceA' => $ids->next(),
        'resourceB' => $ids->next(),
        'context' => $ids->next(),
    ];
}

function issueDelegateGrant(
    ActorContext $initiator,
    Identifier $subject,
    Identifier $resource,
    Identifier $context,
    DateTimeImmutable $now,
    ?DateTimeImmutable $validFrom = null,
    ?DateTimeImmutable $validUntil = null,
): Identifier {
    return app(GrantContextualAccess::class)->grant(
        $initiator,
        $subject,
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $resource,
        'self',
        $context,
        'test_grant',
        $now,
        $validFrom,
        $validUntil,
    );
}

function lookupDelegateGrant(Identifier $subject, Identifier $resource, Identifier $context): ?Identifier
{
    return app(GrantStore::class)->findActive(
        $subject,
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $resource,
        'self',
        $context,
    );
}

it('authorizes a currently valid contextual delegate grant on resource A', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    $grantId = issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('-1 hour'),
        $now->modify('+1 hour'),
    );

    $found = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $decision = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceA'],
        'self',
        $fx['context'],
    );

    expect($found?->value)->toBe($grantId->value)
        ->and($decision->allowed)->toBeTrue();
});

it('denies a not-yet-valid unrevoked contextual grant on findActive and DefaultDenyAuthorizer', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('+1 hour'),
        $now->modify('+2 hours'),
    );

    $found = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $decision = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceA'],
        'self',
        $fx['context'],
    );

    expect($found)->toBeNull()
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe('capability_absent');
});

it('denies an unrevoked expired contextual grant on findActive and DefaultDenyAuthorizer', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('-2 hours'),
        $now->modify('-1 hour'),
    );

    $found = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $decision = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceA'],
        'self',
        $fx['context'],
    );

    expect($found)->toBeNull()
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe('capability_absent');
});

it('treats valid_from as inclusive at the current instant for findActive and activeCapabilities', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    $grantId = issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now,
        $now->modify('+1 hour'),
    );

    $foundAtStart = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $capabilitiesAtStart = app(GrantStore::class)->activeCapabilities($fx['subject'], $now);

    $clock = app(Clock::class);
    expect($clock)->toBeInstanceOf(FrozenClock::class);
    $clock->set($now->modify('-1 microsecond'));

    $foundBeforeStart = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $capabilitiesBeforeStart = app(GrantStore::class)->activeCapabilities($fx['subject'], $clock->now());

    expect($foundAtStart?->value)->toBe($grantId->value)
        ->and($capabilitiesAtStart)->toContain(Capabilities::CONTEXT_DELEGATE)
        ->and($foundBeforeStart)->toBeNull()
        ->and($capabilitiesBeforeStart)->not->toContain(Capabilities::CONTEXT_DELEGATE);
});

it('treats valid_until as inclusive at the current instant for findActive and activeCapabilities', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    $grantId = issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('-1 hour'),
        $now,
    );

    $foundAtEnd = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $capabilitiesAtEnd = app(GrantStore::class)->activeCapabilities($fx['subject'], $now);

    $clock = app(Clock::class);
    expect($clock)->toBeInstanceOf(FrozenClock::class);
    $clock->set($now->modify('+1 microsecond'));

    $foundAfterEnd = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $capabilitiesAfterEnd = app(GrantStore::class)->activeCapabilities($fx['subject'], $clock->now());
    $denied = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceA'],
        'self',
        $fx['context'],
    );

    expect($foundAtEnd?->value)->toBe($grantId->value)
        ->and($capabilitiesAtEnd)->toContain(Capabilities::CONTEXT_DELEGATE)
        ->and($foundAfterEnd)->toBeNull()
        ->and($capabilitiesAfterEnd)->not->toContain(Capabilities::CONTEXT_DELEGATE)
        ->and($denied->allowed)->toBeFalse();
});

it('does not let an expired grant on resource A authorize resource B', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('-2 hours'),
        $now->modify('-1 hour'),
    );

    $onA = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceA'],
        'self',
        $fx['context'],
    );
    $onB = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceB'],
        'self',
        $fx['context'],
    );

    expect($onA->allowed)->toBeFalse()
        ->and($onB->allowed)->toBeFalse()
        ->and(lookupDelegateGrant($fx['subject'], $fx['resourceB'], $fx['context']))->toBeNull();
});

it('does not let a not-yet-valid grant on resource A authorize resource B', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('+1 hour'),
        $now->modify('+2 hours'),
    );

    $onB = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceB'],
        'self',
        $fx['context'],
    );

    expect($onB->allowed)->toBeFalse()
        ->and(lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']))->toBeNull()
        ->and(lookupDelegateGrant($fx['subject'], $fx['resourceB'], $fx['context']))->toBeNull();
});

it('denies a revoked contextual grant even when the validity window still covers now', function () {
    $now = new DateTimeImmutable('2026-08-30T12:00:00.000000Z');
    $fx = arrangeGrantValidityActors($now);
    $grantId = issueDelegateGrant(
        $fx['initiator'],
        $fx['subject'],
        $fx['resourceA'],
        $fx['context'],
        $now,
        $now->modify('-1 hour'),
        $now->modify('+1 hour'),
    );

    app(RevokeContextualAccess::class)->revoke($fx['initiator'], $grantId, $now);

    $found = lookupDelegateGrant($fx['subject'], $fx['resourceA'], $fx['context']);
    $decision = app(Authorize::class)->decide(
        $fx['actor'],
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $fx['resourceA'],
        'self',
        $fx['context'],
    );

    expect($found)->toBeNull()
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe('capability_absent');
});
