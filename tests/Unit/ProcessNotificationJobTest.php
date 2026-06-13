<?php

namespace Tests\Unit;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\NotificationProviderClient;
use App\Services\Observability\Metrics;
use App\Services\Exceptions\TransientProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
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
        // Bu test provider başarılı döndüğünde queued kaydın sent'e taşındığını doğrular.
        // Durum güncellenip provider_response ve attempt_count beklenen şekildeyse test geçer.
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

        (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class), app(Metrics::class));

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
        // Bu test cancelled durumundaki kaydın job tarafından tekrar işlenmediğini kontrol eder.
        // Durum değişmeden cancelled kalırsa test geçer.
        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'cancelled-item',
            'priority' => 'normal',
            'status' => Notification::STATUS_CANCELLED,
        ]);

        (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class), app(Metrics::class));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_CANCELLED,
        ]);
    }

    public function test_job_is_safe_when_notification_does_not_exist(): void
    {
        // Bu test olmayan bir notification ID ile handle çağrıldığında sistemin patlamadığını doğrular.
        // Exception atmadan akış tamamlanırsa test geçer.
        (new ProcessNotificationJob('00000000-0000-0000-0000-000000000000'))->handle(app(NotificationProviderClient::class), app(Metrics::class));

        $this->assertTrue(true);
    }

    public function test_job_marks_failed_on_permanent_provider_error_without_throwing(): void
    {
        // Bu test provider 4xx döndüğünde kalıcı hata olarak işaretlenmesini kontrol eder.
        // Job exception fırlatmadan kayıt failed olursa test geçer.
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

        (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class), app(Metrics::class));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => Notification::STATUS_FAILED,
        ]);
    }

    public function test_job_rethrows_transient_provider_error_and_keeps_notification_queued(): void
    {
        // Bu test provider 5xx döndüğünde geçici hata davranışını kontrol eder.
        // Exception yeniden fırlatılırken kayıt queued kalıyorsa retry akışı doğru, test geçer.
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
            (new ProcessNotificationJob($notification->id))->handle(app(NotificationProviderClient::class), app(Metrics::class));
        } finally {
            $this->assertDatabaseHas('notifications', [
                'id' => $notification->id,
                'status' => Notification::STATUS_QUEUED,
            ]);
        }
    }

    public function test_job_backoff_matches_retry_policy(): void
    {
        // Bu test retry politikasının sabit değerlerini doğrular.
        // backoff dizisi ve toplam tries değeri beklendiği gibiyse test geçer.
        $job = new ProcessNotificationJob('00000000-0000-0000-0000-000000000000');

        $this->assertSame([5, 30, 120], $job->backoff());
        $this->assertSame(4, $job->tries);
    }

    public function test_job_releases_when_channel_rate_limit_is_exceeded(): void
    {
        // Bu test kanal limiti dolduğunda job'ın provider'a gitmeden release edilmesini doğrular.
        // HTTP çağrısı yapılmaz, kayıt queued kalır ve last_error yazılırsa test geçer.
        config()->set('notifications.rate_limit.per_second', 1);
        config()->set('notifications.rate_limit.release_seconds', 1);

        $notification = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'rate-limited-item',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        $bucketKey = sprintf('notifications:channel:%s:%s', $notification->channel, now()->format('YmdHis'));
        RateLimiter::hit($bucketKey, 1);

        Http::fake([
            'https://example.test/provider' => Http::response([
                'messageId' => 'provider-msg-1',
                'status' => 'accepted',
                'timestamp' => now()->toISOString(),
            ], 202),
        ]);

        $job = Mockery::mock(ProcessNotificationJob::class, [$notification->id])->makePartial();
        $job->shouldReceive('release')->once()->with(1);

        $job->handle(app(NotificationProviderClient::class), app(Metrics::class));

        Http::assertNothingSent();

        $notification->refresh();

        $this->assertSame(Notification::STATUS_QUEUED, $notification->status);
        $this->assertSame('Rate limit exceeded for channel: sms', $notification->last_error);
    }
}
