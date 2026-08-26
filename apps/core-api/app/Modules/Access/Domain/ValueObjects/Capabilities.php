<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\ValueObjects;

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
    ];

    /** Clinical, pharmacy-stock, and catalog capabilities are absent on purpose. */
    public static function isKnown(string $capability): bool
    {
        return in_array($capability, [...self::AUTHENTICATED_SELF, ...self::PRIVILEGED_OPERATOR], true);
    }

    /**
     * @return list<string>
     */
    public static function forActor(string $accountType, bool $privilegedAssurance): array
    {
        if ($accountType === 'admin' && $privilegedAssurance) {
            return [...self::AUTHENTICATED_SELF, ...self::PRIVILEGED_OPERATOR];
        }

        return self::AUTHENTICATED_SELF;
    }
}
