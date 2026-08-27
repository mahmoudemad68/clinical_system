<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Modules\Platform\Contracts\SendPush;
use Modules\Platform\Services\Notifications\DeliverUserVisibleNotification;
use Modules\Platform\Services\Notifications\DeliverUserVisibleNotificationService;
use Modules\Platform\Services\Testing\RecordingSendPush;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('user-visible notifications', function () {
    it('stores a database notification even when push is also sent', function () {
        $user = User::factory()->create();
        $push = new RecordingSendPush;
        app()->instance(SendPush::class, $push);

        $handler = app(DeliverUserVisibleNotificationService::class);
        $handler->handle(new DeliverUserVisibleNotification(
            notifiableType: $user::class,
            notifiableId: (string) $user->id,
            notificationType: 'generic',
            data: ['ref' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01'],
            deviceTokenFingerprint: 'device-fingerprint-not-a-token-log',
        ));

        expect(DatabaseNotification::query()->count())->toBe(1)
            ->and($push->sent)->toHaveCount(1)
            ->and($push->sent[0]['type'])->toBe('generic');

        $row = DatabaseNotification::query()->first();
        expect($row)->not->toBeNull()
            ->and($row->data['notification_type'])->toBe('generic')
            ->and($row->data['ref'])->toBe('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01')
            ->and(json_encode($row->data))->not->toContain('chest pain')
            ->and(json_encode($row->data))->not->toContain('patient');
    });

    it('keeps the inbox row when push delivery fails', function () {
        $user = User::factory()->create();
        app()->instance(SendPush::class, new RecordingSendPush(fail: true));

        app(DeliverUserVisibleNotificationService::class)->handle(new DeliverUserVisibleNotification(
            notifiableType: $user::class,
            notifiableId: (string) $user->id,
            notificationType: 'generic',
            data: ['ref' => 'n-1'],
            deviceTokenFingerprint: 'device-fingerprint',
        ));

        expect(DatabaseNotification::query()->count())->toBe(1);
    });

    it('does not invent a parallel stored-notification table', function () {
        expect(Schema::hasTable('notifications'))->toBeTrue()
            ->and(Schema::hasTable('inbox_messages'))->toBeFalse()
            ->and(Schema::hasTable('push_receipts'))->toBeFalse();
    });
});
