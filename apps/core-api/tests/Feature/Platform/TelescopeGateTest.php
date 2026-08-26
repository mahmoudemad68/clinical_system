<?php

declare(strict_types=1);

use App\Providers\TelescopeServiceProvider as AppTelescopeServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

describe('Telescope gating', function () {
    it('is not registered in the testing environment', function () {
        expect(app()->environment('testing'))->toBeTrue();
        expect(config('telescope.enabled'))->toBeFalse();
        expect(app()->providerIsLoaded(TelescopeServiceProvider::class))->toBeFalse();
        expect(app()->providerIsLoaded(AppTelescopeServiceProvider::class))->toBeFalse();

        $this->get('/telescope')->assertNotFound();
    });

    it('does not ship Telescope tables on the production migration path', function () {
        $published = glob(database_path('migrations').'/*telescope*') ?: [];

        expect($published)->toBeEmpty();
    });
});
