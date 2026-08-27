<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantContextualAccess;
use Modules\Access\Contracts\ListEffectiveCapabilities;
use Modules\Access\Contracts\RevokeContextualAccess;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\DisableIdentityService;
use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function syntheticActor(Identifier $userId): ActorContext
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

function operatorActor(Identifier $userId): ActorContext
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

it('enforces one active contextual grant per lookup tuple', function () {
    $user = User::factory()->create([
        'status' => AccountStatus::PendingPhone->value,
        'phone_verified_at' => null,
    ]);
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);
    $userId = Identifier::fromTrusted((string) $user->id);
    $initiator = operatorActor(Identifier::fromTrusted((string) $admin->id));
    $ids = app(IdentityGenerator::class);
    $now = app(Clock::class)->now();
    $resource = $ids->next();
    $context = $ids->next();
    $grants = app(GrantContextualAccess::class);

    $first = $grants->grant(
        $initiator,
        $userId,
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $resource,
        'self',
        $context,
        'test_grant',
        $now,
    );
    $second = $grants->grant(
        $initiator,
        $userId,
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $resource,
        'self',
        $context,
        'test_grant',
        $now,
    );

    expect($first->value)->toBe($second->value)
        ->and(DB::table('contextual_access_grants')->count())->toBe(1)
        ->and(DB::table('contextual_access_grants')->value('issued_by_id'))->toBe($admin->id);

    app(RevokeContextualAccess::class)->revoke($initiator, $first, $now);
    expect(DB::table('contextual_access_grants')->whereNull('revoked_at')->count())->toBe(0);

    $listed = app(ListEffectiveCapabilities::class)->forActor(syntheticActor($userId), $now);
    expect($listed)->not->toContain('clinical.record.read');
});

it('rejects a grant from a patient actor', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $ids = app(IdentityGenerator::class);
    $now = app(Clock::class)->now();

    expect(fn () => app(GrantContextualAccess::class)->grant(
        syntheticActor($userId),
        $userId,
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $ids->next(),
        'self',
        $ids->next(),
        'test_grant',
        $now,
    ))->toThrow(AuthorizationDenied::class);
});

it('rejects a second active grant row at the unique index', function () {
    $user = User::factory()->create([
        'status' => AccountStatus::PendingPhone->value,
        'phone_verified_at' => null,
    ]);
    $ids = app(IdentityGenerator::class);
    $now = app(Clock::class)->now();
    $resource = $ids->next();
    $context = $ids->next();
    $row = [
        'actor_user_id' => $user->id,
        'capability' => Capabilities::CONTEXT_DELEGATE,
        'resource_type' => 'auth_session',
        'resource_id' => $resource->value,
        'context_type' => 'self',
        'context_id' => $context->value,
        'valid_from' => null,
        'valid_until' => null,
        'revoked_at' => null,
        'reason_code' => 'test_grant',
        'issued_by_type' => 'system',
        'issued_by_id' => $user->id,
        'version' => 1,
        'created_at' => $now,
    ];

    DB::table('contextual_access_grants')->insert(['id' => $ids->next()->value] + $row);

    expect(fn () => DB::table('contextual_access_grants')->insert(['id' => $ids->next()->value] + $row))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('disables an identity and increments credential version', function () {
    $user = User::factory()->create([
        'status' => AccountStatus::Active->value,
    ]);
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);

    app(DisableIdentityService::class)->handle(
        operatorActor(Identifier::fromTrusted((string) $admin->id)),
        Identifier::fromTrusted((string) $user->id),
        AccountStatus::Locked,
        'security_lock',
    );

    $row = DB::table('users')->where('id', $user->id)->first();
    expect($row->status)->toBe('locked')
        ->and((int) $row->credential_version)->toBe(2);
});

it('rejects identity disable from a patient actor', function () {
    $user = User::factory()->create();

    expect(fn () => app(DisableIdentityService::class)->handle(
        syntheticActor(Identifier::fromTrusted((string) $user->id)),
        Identifier::fromTrusted((string) $user->id),
        AccountStatus::Locked,
        'security_lock',
    ))->toThrow(AuthorizationDenied::class);
});

it('does not disclose profile claim while the flag is off', function () {
    $actor = syntheticActor(Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09'));

    expect(fn () => app(LinkVerifiedPatientAccount::class)->handle($actor, '99999990100011'))
        ->toThrow(FeatureUnavailable::class);
});

it('rejects a grant of an operator capability and a grant from stale AAL1 admin', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);
    $ids = app(IdentityGenerator::class);
    $now = app(Clock::class)->now();
    $aal1 = new ActorContext(
        Identifier::fromTrusted((string) $admin->id),
        AccountType::Admin,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal1Password,
        1,
        null,
        Identifier::fromTrusted((string) $admin->id),
        [],
        Capabilities::forActor('admin', false),
    );

    expect(fn () => app(GrantContextualAccess::class)->grant(
        operatorActor(Identifier::fromTrusted((string) $admin->id)),
        Identifier::fromTrusted((string) $user->id),
        Capabilities::ACCESS_GRANT_ISSUE,
        'auth_session',
        $ids->next(),
        'self',
        $ids->next(),
        'test_grant',
        $now,
    ))->toThrow(InvalidValueObject::class);

    expect(fn () => app(GrantContextualAccess::class)->grant(
        $aal1,
        Identifier::fromTrusted((string) $user->id),
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $ids->next(),
        'self',
        $ids->next(),
        'test_grant',
        $now,
    ))->toThrow(AuthorizationDenied::class);
});

it('does not allow a grant on resource A to authorize resource B', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);
    $ids = app(IdentityGenerator::class);
    $now = app(Clock::class)->now();
    $resourceA = $ids->next();
    $resourceB = $ids->next();
    $context = $ids->next();
    $subject = Identifier::fromTrusted((string) $user->id);

    app(GrantContextualAccess::class)->grant(
        operatorActor(Identifier::fromTrusted((string) $admin->id)),
        $subject,
        Capabilities::CONTEXT_DELEGATE,
        'auth_session',
        $resourceA,
        'self',
        $context,
        'test_grant',
        $now,
    );

    $actor = syntheticActor($subject);
    $authorizer = app(Authorize::class);
    $allowed = $authorizer->decide($actor, Capabilities::CONTEXT_DELEGATE, 'auth_session', $resourceA, 'self', $context);
    $denied = $authorizer->decide($actor, Capabilities::CONTEXT_DELEGATE, 'auth_session', $resourceB, 'self', $context);

    expect($allowed->allowed)->toBeTrue()
        ->and($denied->allowed)->toBeFalse();
});
