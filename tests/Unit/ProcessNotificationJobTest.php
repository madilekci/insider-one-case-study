<?php

namespace Tests\Unit;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\NotificationProviderClient;
use App\Services\Exceptions\TransientProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('notifications.provider.webhook_url', 'https://example.test/provider');
    }

    public function test_job_moves_queued_notification_to_sent(): void
    {
        Http::fake([
            'https://example.test/provider' => Http::response([
                'messageId' => 'provider-msg-1',
                'status' => 'accepted',
                'timestamp' => now()->toISOString(),
            ], 202),
        ]);

        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'to-be-sent',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_SENT,
        ]);

        $notification->refresh();

        $this->assertSame('provider-msg-1', $notification->provider_response['messageId'] ?? null);
        $this->assertSame(1, $notification->attempt_count);
    }

    public function test_job_does_not_process_cancelled_notification(): void
    {
        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'cancelled-item',
            'priority' => 'normal',
            'status' => Notification::STATUS_CANCELLED,
        ]);

        (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_CANCELLED,
        ]);
    }

    public function test_job_is_safe_when_notification_does_not_exist(): void
    {
        (new ProcessNotificationJob('00000000-0000-0000-0000-000000000000'))->handle(app(NotificationProviderClient::class));

        $this->assertTrue(true);
    }

    public function test_job_marks_failed_on_permanent_provider_error_without_throwing(): void
    {
        Http::fake([
            'https://example.test/provider' => Http::response([
                'error' => 'invalid recipient',
            ], 422),
        ]);

        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => 'bad-recipient',
            'content' => 'bad',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_FAILED,
        ]);
    }

    public function test_job_rethrows_transient_provider_error_and_keeps_notification_queued(): void
    {
        Http::fake([
            'https://example.test/provider' => Http::response([
                'error' => 'temporary issue',
            ], 500),
        ]);

        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'retry-me',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        $this->expectException(TransientProviderException::class);

        try {
            (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class));
        } finally {
            $this->assertDatabaseHas('notifications', [
                'id' => $notification->id,
                'status' => Notification::STATUS_QUEUED,
            ]);
        }
    }

    public function test_job_backoff_matches_retry_policy(): void
    {
        $job = new ProcessNotificationJob('00000000-0000-0000-0000-000000000000');

        $this->assertSame([5, 30, 120], $job->backoff());
        $this->assertSame(4, $job->tries);
    }
}
