<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure\Providers;

use App\Modules\Access\Application\DefaultDenyAuthorizer;
use App\Modules\Access\Application\GrantContextualAccessHandler;
use App\Modules\Access\Application\ListEffectiveCapabilitiesHandler;
use App\Modules\Access\Application\RevokeContextualAccessHandler;
use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\Contracts\GrantContextualAccess;
use App\Modules\Access\Domain\Contracts\GrantStore;
use App\Modules\Access\Domain\Contracts\ListEffectiveCapabilities;
use App\Modules\Access\Domain\Contracts\RevokeContextualAccess;
use App\Modules\Access\Infrastructure\Persistence\PostgresGrantStore;
use Illuminate\Support\ServiceProvider;

final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Authorize::class, DefaultDenyAuthorizer::class);
        $this->app->singleton(GrantStore::class, PostgresGrantStore::class);
        $this->app->singleton(GrantContextualAccess::class, GrantContextualAccessHandler::class);
        $this->app->singleton(RevokeContextualAccess::class, RevokeContextualAccessHandler::class);
        $this->app->singleton(ListEffectiveCapabilities::class, ListEffectiveCapabilitiesHandler::class);
    }
}
