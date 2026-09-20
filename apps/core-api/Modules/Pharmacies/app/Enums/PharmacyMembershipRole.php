<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Enums;

/**
 * Phase-02 V1 pharmacy membership roles. This is not the Phase-10
 * OWNER/PHARMACIST/CASHIER/INVENTORY/PURCHASING/CONNECTOR_SERVICE matrix.
 */
enum PharmacyMembershipRole: string
{
    case Owner = 'owner';
    case BranchOperator = 'branch_operator';

    public function confersBusinessCapability(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
