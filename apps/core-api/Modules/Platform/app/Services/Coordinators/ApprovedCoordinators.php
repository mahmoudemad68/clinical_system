<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Coordinators;

/**
 * The only classes allowed to call public services from more than one module
 * inside one transaction (Phase 00 cross-module coordination).
 *
 * Names are stored as strings so Platform does not import business modules.
 * Later phases add names here in the same change that introduces the class.
 * The architecture test fails if a *Coordinator class reappears, or if an
 * outbox consumer starts calling another module's persistence types.
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
            'Modules\\Auth\\Services\\RegisterAccountService',
            'Modules\\Identity\\Services\\DisableIdentityService',
            'Modules\\Identity\\Services\\EraseSubjectService',
            'Modules\\Identity\\Services\\RotateIdentityKeysService',
            'Modules\\Patients\\Services\\CreatePatientProfile',
            'Modules\\Patients\\Services\\UpdateOwnDemographics',
            'Modules\\Patients\\Services\\CreateUnlinkedPatientProfile',
        ];
    }
}
