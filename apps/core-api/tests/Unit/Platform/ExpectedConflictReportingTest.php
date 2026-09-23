<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\VersionConflict;
use Tests\TestCase;

uses(TestCase::class);

it('does not report expected version and state conflicts', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(new VersionConflict))
        ->toBeFalse()
        ->and($handler->shouldReport(new StateConflict))->toBeFalse();
});
