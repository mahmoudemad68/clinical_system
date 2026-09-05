<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

/*
 * Application providers only. Nwidart registers each module's provider from
 * module.json (priority: Platform, Audit, Identity, Auth, Access, Patients). Listing
 * those classes here would double-register them.
 */
return [
    AppServiceProvider::class,
];
