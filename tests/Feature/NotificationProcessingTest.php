<?php

namespace Tests\Feature;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_single_notification_to_priority_queue(): void
    {
        Queue::fake();

        $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'priority-test',
            'priority' => 'high',
        ])->assertCreated();

        Queue::assertPushedOn('high', ProcessNotificationJob::class);
        Queue::assertPushed(ProcessNotificationJob::class, 1);
    }

    public function test_it_dispatches_batch_notifications_to_separate_priority_queues(): void
    {
        Queue::fake();

        $this->postJson('/api/notifications', [
            'notifications' => [
                [
                    'channel' => 'sms',
                    'recipient' => '+905551234567',
                    'content' => 'high-msg',
                    'priority' => 'high',
                ],
                [
                    'channel' => 'email',
                    'recipient' => 'normal@example.com',
                    'content' => 'normal-msg',
                    'priority' => 'normal',
                ],
                [
                    'channel' => 'push',
                    'recipient' => 'device-token-1',
                    'content' => 'low-msg',
                    'priority' => 'low',
                ],
            ],
        ])->assertCreated();

        Queue::assertPushedOn('high', ProcessNotificationJob::class);
        Queue::assertPushedOn('normal', ProcessNotificationJob::class);
        Queue::assertPushedOn('low', ProcessNotificationJob::class);
        Queue::assertPushed(ProcessNotificationJob::class, 3);
    }

    public function test_single_idempotency_header_returns_existing_notification_without_duplicate_dispatch(): void
    {
        Queue::fake();

        $first = $this->withHeaders([
            'Idempotency-Key' => 'single-key-1',
        ])->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'first-content',
            'priority' => 'normal',
        ])->assertCreated();

        $firstId = $first->json('notification.id');

        $second = $this->withHeaders([
            'Idempotency-Key' => 'single-key-1',
        ])->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905559999999',
            'content' => 'second-content',
            'priority' => 'high',
        ])->assertOk();

        $second->assertJsonPath('notification.id', $firstId);

        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(ProcessNotificationJob::class, 1);
    }

    public function test_batch_item_idempotency_reuses_existing_record_and_dispatches_only_new_item(): void
    {
        Queue::fake();

        Notification::create([
            'idempotency_key' => 'batch-item-existing',
            'channel' => 'sms',
            'recipient' => '+905550000001',
            'content' => 'existing-content',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        $response = $this->postJson('/api/notifications', [
            'notifications' => [
                [
                    'idempotency_key' => 'batch-item-existing',
                    'channel' => 'sms',
                    'recipient' => '+905551111111',
                    'content' => 'should-not-create',
                    'priority' => 'high',
                ],
                [
                    'idempotency_key' => 'batch-item-new',
                    'channel' => 'email',
                    'recipient' => 'new@example.com',
                    'content' => 'new-content',
                    'priority' => 'low',
                ],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('count', 2);

        $this->assertDatabaseCount('notifications', 2);

        Queue::assertPushed(ProcessNotificationJob::class, 1);
        Queue::assertPushedOn('low', ProcessNotificationJob::class);
    }
}
