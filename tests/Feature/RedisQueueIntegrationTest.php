<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'redis');

        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable for integration tests: '.$e->getMessage());
        }

        Redis::del('queues:high', 'queues:normal', 'queues:low');
    }

    protected function tearDown(): void
    {
        Redis::del('queues:high', 'queues:normal', 'queues:low');

        parent::tearDown();
    }

    public function test_notification_is_pushed_to_redis_and_processed_by_worker_once(): void
    {
        $response = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'redis-integration',
            'priority' => 'high',
        ]);

        $response->assertCreated();

        $notificationId = $response->json('notification.id');

        $this->assertSame(1, Redis::llen('queues:high'));

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'high,normal,low',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $this->assertSame(0, Redis::llen('queues:high'));

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'status' => Notification::STATUS_SENT,
        ]);
    }

    public function test_idempotency_key_does_not_push_duplicate_redis_job(): void
    {
        $this->withHeaders([
            'Idempotency-Key' => 'redis-idempotent-1',
        ])->postJson('/api/notifications', [
            'channel' => 'email',
            'recipient' => 'user@example.com',
            'content' => 'first',
            'priority' => 'normal',
        ])->assertCreated();

        $this->withHeaders([
            'Idempotency-Key' => 'redis-idempotent-1',
        ])->postJson('/api/notifications', [
            'channel' => 'email',
            'recipient' => 'other@example.com',
            'content' => 'second',
            'priority' => 'normal',
        ])->assertOk();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, Redis::llen('queues:normal'));
    }

    public function test_batch_items_are_pushed_to_matching_priority_queues(): void
    {
        $this->postJson('/api/notifications', [
            'notifications' => [
                [
                    'channel' => 'sms',
                    'recipient' => '+905551234111',
                    'content' => 'high-priority',
                    'priority' => 'high',
                ],
                [
                    'channel' => 'email',
                    'recipient' => 'normal@example.com',
                    'content' => 'normal-priority',
                    'priority' => 'normal',
                ],
                [
                    'channel' => 'push',
                    'recipient' => 'device-token-123',
                    'content' => 'low-priority',
                    'priority' => 'low',
                ],
            ],
        ])->assertCreated();

        $this->assertSame(1, Redis::llen('queues:high'));
        $this->assertSame(1, Redis::llen('queues:normal'));
        $this->assertSame(1, Redis::llen('queues:low'));
    }

    public function test_cancelled_notification_stays_cancelled_when_worker_runs(): void
    {
        $create = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234222',
            'content' => 'cancel-me',
            'priority' => 'high',
        ])->assertCreated();

        $notificationId = $create->json('notification.id');

        $this->postJson("/api/notifications/{$notificationId}/cancel")
            ->assertOk()
            ->assertJsonPath('notification.status', Notification::STATUS_CANCELLED);

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'high,normal,low',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'status' => Notification::STATUS_CANCELLED,
        ]);
    }

    public function test_worker_once_processes_high_before_low_when_both_queued(): void
    {
        $high = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234333',
            'content' => 'high-first',
            'priority' => 'high',
        ])->assertCreated();

        $low = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234444',
            'content' => 'low-second',
            'priority' => 'low',
        ])->assertCreated();

        $highId = $high->json('notification.id');
        $lowId = $low->json('notification.id');

        $this->assertSame(1, Redis::llen('queues:high'));
        $this->assertSame(1, Redis::llen('queues:low'));

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'high,normal,low',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $highId,
            'status' => Notification::STATUS_SENT,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $lowId,
            'status' => Notification::STATUS_QUEUED,
        ]);

        $this->assertSame(0, Redis::llen('queues:high'));
        $this->assertSame(1, Redis::llen('queues:low'));
    }
}
