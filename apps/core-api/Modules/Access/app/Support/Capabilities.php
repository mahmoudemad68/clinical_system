<?php

declare(strict_types=1);

namespace Modules\Access\Support;

/**
 * Capability names owned by Access. Resource modules register additional
 * names later; unknown names deny.
 */
final class Capabilities
{
    public const SESSION_LIST_OWN = 'auth.session.list_own';

    public const SESSION_REVOKE_OWN = 'auth.session.revoke_own';

    public const SESSION_REVOKE_ALL = 'auth.session.revoke_all';

    public const PASSWORD_CHANGE = 'auth.password.change';

    public const IDENTITY_ME_READ = 'identity.me.read';

    public const IDENTITY_CAPABILITIES_READ = 'identity.capabilities.read';

    public const MFA_MANAGE_SELF = 'auth.mfa.manage_self';

    public const ACCESS_GRANT_ISSUE = 'access.grant.issue';

    public const ACCESS_GRANT_REVOKE = 'access.grant.revoke';

    public const IDENTITY_DISABLE = 'identity.disable';

    public const IDENTITY_ERASE = 'identity.erase';

    public const IDENTITY_EXPORT = 'identity.export';

    public const RECOVERY_APPLY = 'auth.recovery.apply';

    /** Resource-scoped placeholder until later phases register clinical names. */
    public const CONTEXT_DELEGATE = 'access.context.delegate';

    /** @var list<string> */
    public const GRANTABLE_RESOURCE_TYPES = ['auth_session', 'user', 'organization', 'branch'];

    /** @var list<string> */
    public const AUTHENTICATED_SELF = [
        self::SESSION_LIST_OWN,
        self::SESSION_REVOKE_OWN,
        self::SESSION_REVOKE_ALL,
        self::PASSWORD_CHANGE,
        self::IDENTITY_ME_READ,
        self::IDENTITY_CAPABILITIES_READ,
        self::MFA_MANAGE_SELF,
    ];

    /** @var list<string> */
    public const PRIVILEGED_OPERATOR = [
        self::ACCESS_GRANT_ISSUE,
        self::ACCESS_GRANT_REVOKE,
        self::IDENTITY_DISABLE,
        self::IDENTITY_ERASE,
        self::IDENTITY_EXPORT,
        self::RECOVERY_APPLY,
    ];

    /** @var list<string> */
    public const GRANTABLE = [
        self::CONTEXT_DELEGATE,
    ];

    /**
     * Capabilities available while a bootstrap password change is required.
     *
     * @var list<string>
     */
    public const PASSWORD_CHANGE_REQUIRED = [
        self::PASSWORD_CHANGE,
        self::SESSION_REVOKE_ALL,
    ];

    /** Clinical, pharmacy-stock, and catalog capabilities are absent on purpose. */
    public static function isKnown(string $capability): bool
    {
        return in_array($capability, [...self::AUTHENTICATED_SELF, ...self::PRIVILEGED_OPERATOR, ...self::GRANTABLE], true);
    }

    public static function isGrantable(string $capability): bool
    {
        return in_array($capability, self::GRANTABLE, true);
    }

    public static function isGrantableResourceType(string $resourceType): bool
    {
        return in_array($resourceType, self::GRANTABLE_RESOURCE_TYPES, true);
    }

    /**
     * @return list<string>
     */
    public static function forActor(string $accountType, bool $privilegedAssurance, bool $passwordMustChange = false): array
    {
        if ($passwordMustChange) {
            return self::PASSWORD_CHANGE_REQUIRED;
        }

        if ($accountType === 'admin' && $privilegedAssurance) {
            return [...self::AUTHENTICATED_SELF, ...self::PRIVILEGED_OPERATOR];
        }

        return self::AUTHENTICATED_SELF;
    }
}
