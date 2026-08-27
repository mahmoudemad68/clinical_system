<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Telemetry\RedactingLogTap;
use App\Modules\Platform\Infrastructure\Telemetry\SentryBeforeSend;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

uses(TestCase::class);

it('redacts a canary national id before a log sink persists the record', function () {
    $handler = new TestHandler(Level::Debug);
    $monolog = new MonologLogger('canary');
    $monolog->pushHandler($handler);
    $tap = app(RedactingLogTap::class);
    $tap(new Logger($monolog));

    $monolog->info('identity lookup', [
        'national_id' => '29901011234567',
        'correlation_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09',
    ]);

    $records = $handler->getRecords();
    expect($records)->not->toBeEmpty();
    $encoded = json_encode($records[0]);
    expect($encoded)->not->toContain('29901011234567')
        ->and($encoded)->toContain('redacted');
});

it('strips request bodies from a sentry envelope', function () {
    $event = new class
    {
        /** @var array<string, mixed> */
        private array $request = [
            'cookies' => ['clinic_session' => 'secret'],
            'data' => ['password' => 'correct-horse-battery'],
            'headers' => ['authorization' => 'Bearer token'],
        ];

        public function getRequest(): array
        {
            return $this->request;
        }

        public function setRequest(array $request): void
        {
            $this->request = $request;
        }
    };

    $filtered = SentryBeforeSend::filter($event);
    $request = $filtered->getRequest();

    expect($request)->not->toHaveKey('cookies')
        ->and($request)->not->toHaveKey('data')
        ->and($request)->not->toHaveKey('headers');
});
