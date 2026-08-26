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

    /** Clinical, pharmacy-stock, and catalog capabilities are absent on purpose. */
    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::AUTHENTICATED_SELF, true);
    }
}
