<?php

namespace Tests\Unit;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_moves_queued_notification_to_sent(): void
    {
        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'to-be-sent',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        (new ProcessNotificationJob($notification->id))->handle();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_SENT,
        ]);
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

        (new ProcessNotificationJob($notification->id))->handle();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_CANCELLED,
        ]);
    }

    public function test_job_is_safe_when_notification_does_not_exist(): void
    {
        (new ProcessNotificationJob('00000000-0000-0000-0000-000000000000'))->handle();

        $this->assertTrue(true);
    }
}
