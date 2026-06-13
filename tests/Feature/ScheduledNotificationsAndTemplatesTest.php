<?php

namespace Tests\Feature;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledNotificationsAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_lists_available_templates(): void
    {
        $this->getJson('/api/templates')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'welcome_sms');
    }

    public function test_it_renders_template_content_before_queueing(): void
    {
        $response = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template_key' => 'welcome_sms',
            'template_variables' => [
                'name' => 'Ada',
                'code' => '123456',
            ],
            'priority' => 'high',
        ]);

        $response->assertCreated()
            ->assertJsonPath('notification.template_key', 'welcome_sms')
            ->assertJsonPath('notification.content', 'Welcome Ada! Your verification code is 123456.')
            ->assertJsonPath('notification.status', Notification::STATUS_QUEUED);

        Queue::assertPushedOn('high', ProcessNotificationJob::class);
    }

    public function test_it_rejects_missing_template_variables(): void
    {
        $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template_key' => 'welcome_sms',
            'template_variables' => [
                'name' => 'Ada',
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('template_variables');
    }

    public function test_it_creates_future_notifications_as_pending(): void
    {
        $scheduledAt = now()->addMinutes(30)->startOfSecond()->toISOString();

        $response = $this->postJson('/api/notifications', [
            'channel' => 'email',
            'recipient' => 'user@example.com',
            'content' => 'Scheduled message',
            'scheduled_at' => $scheduledAt,
            'priority' => 'normal',
        ]);

        $response->assertCreated()
            ->assertJsonPath('notification.status', Notification::STATUS_PENDING)
            ->assertJsonPath('notification.scheduled_at', $scheduledAt);

        Queue::assertPushedOn('normal', ProcessNotificationJob::class);
    }
}