<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Coordinators;

use App\Modules\Auth\Application\RegisterAccountCoordinator;
use App\Modules\Identity\Application\DisableIdentityCoordinator;

/**
 * The only classes allowed to call command ports from more than one module
 * inside one transaction (Phase 00 cross-module coordination).
 *
 * Phase 00 has none: the diagnostics slice lives entirely inside Platform.
 * Later phases add names here in the same change that introduces the class.
 * The architecture test fails if a *Coordinator class appears without being
 * listed, or if an outbox consumer starts calling a command port.
 *
 * @phpstan-type CoordinatorList list<class-string>
 */
final class ApprovedCoordinators
{
    /**
     * @return CoordinatorList
     */
    public static function classes(): array
    {
        return [
            RegisterAccountCoordinator::class,
            DisableIdentityCoordinator::class,
        ];
    }
}
