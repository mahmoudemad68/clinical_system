<?php

declare(strict_types=1);

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Modules\Platform\Services\Adapters\FirebaseSendPush;
use Modules\Platform\Services\Telemetry\PlatformMetrics;

it('sends generic lock-screen copy through the Firebase messaging contract', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(function (CloudMessage $message): bool {
            $payload = $message->jsonSerialize();

            expect($payload['notification']['title'] ?? null)->toBe('Clinic')
                ->and($payload['notification']['body'] ?? null)->toBe('You have a new notice')
                ->and($payload['token'] ?? null)->toBe('fcm-token-fingerprint')
                ->and(json_encode($payload))->not->toContain('chest pain')
                ->and(json_encode($payload))->not->toContain('patient_id');

            return true;
        })
        ->andReturn(['name' => 'projects/test/messages/1']);

    $adapter = new FirebaseSendPush($messaging, new PlatformMetrics('core-api', 'test'));
    $adapter->send('fcm-token-fingerprint', 'generic', ['ref' => 'n-1']);
});

it('records a provider failure metric when FCM throws', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andThrow(new RuntimeException('unavailable'));

    $metrics = new PlatformMetrics('core-api', 'test');
    $adapter = new FirebaseSendPush($messaging, $metrics);

    expect(fn () => $adapter->send('token', 'generic', ['ref' => 'n-1']))
        ->toThrow(RuntimeException::class);

    expect($metrics->render())->toContain('clinic_provider_failures_total')
        ->and($metrics->render())->toContain('error_class="push"');
});
