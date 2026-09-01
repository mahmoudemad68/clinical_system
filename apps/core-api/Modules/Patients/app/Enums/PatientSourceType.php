<?php

declare(strict_types=1);

namespace Modules\Patients\Enums;

enum PatientSourceType: string
{
    case SelfOnboarding = 'self_onboarding';
    case WalkIn = 'walk_in';
}
