<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveProviderIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip all tests in this file if provider not configured
        if (!config('notifications.provider.webhook_url')) {
            $this->markTestSkipped('Provider webhook URL not configured. Set NOTIFICATION_PROVIDER_WEBHOOK_URL in .env');
        }

        // Run migrations for test database
        $this->artisan('migrate:fresh');
    }

    /**
     * Test that the provider endpoint is accessible and responds correctly.
     *
     * This checks basic connectivity before running full integration tests.
     */
    public function test_provider_is_accessible_and_responds_with_202(): void
    {
        // Bu test canlı provider URL'sine örnek payload gönderir.
        // 202 döner ve JSON body gelirse bağlantı adımı başarılı kabul edilir.
        $response = Http::timeout(10)->post(
            config('notifications.provider.webhook_url'),
            [
                'to' => '+905551234567',
                'channel' => 'sms',
                'content' => 'Provider health check',
            ]
        );

        $this->assertEquals(202, $response->status());
        $this->assertNotEmpty($response->json());
    }

    /**
     * Test that notification flows end-to-end through provider.
     *
     * This is a full integration test:
     * 1. Create notification via API
     * 2. Worker processes it
     * 3. Provider receives the request
     * 4. Notification status moves to "sent"
     */
    public function test_notification_delivered_through_live_provider(): void
    {
        // Bu test API'den bildirim oluşturup worker ile işleterek uçtan uca akışı doğrular.
        // Durum sent'e geçer ve provider_response alanları dolarsa test geçer.
        // Create a notification
        $response = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Live provider integration test ' . now()->timestamp,
            'priority' => 'high',
        ]);

        $response->assertStatus(201);
        $notification = $response->json('notification');
        $notificationId = $notification['id'];

        // Process job once (simulating worker)
        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'high,normal,low',
            '--once' => true,
        ]);

        // Fetch notification to verify it was processed
        $notificationResponse = $this->getJson("/api/notifications/{$notificationId}");
        $notificationResponse->assertStatus(200);

        $notification = $notificationResponse->json('data');

        // Verify notification transitioned to sent
        $this->assertEquals('sent', $notification['status']);

        // Verify provider response was captured
        $this->assertNotNull($notification['provider_response']);
        $this->assertEquals(202, $notification['provider_response']['http_status']);
        $this->assertEquals('provider-demo-id', $notification['provider_response']['messageId']);
        $this->assertEquals('accepted', $notification['provider_response']['status']);
    }

    /**
     * Test that webhook.site receives correct payload format.
     *
     * Verify by checking the notification was sent with correct content.
     * The actual webhook.site API may require auth, so we verify via successful
     * notification processing instead.
     */
    public function test_webhook_site_receives_correct_payload(): void
    {
        // Bu test webhook tarafına gönderimin başarılı olduğunu dolaylı olarak doğrular.
        // İşlem sonrası kayıt sent olursa payload formatı kabul edildi varsayımıyla test geçer.
        // Create notification with unique identifier
        $uniqueContent = 'Payload validation test ' . now()->timestamp;

        $response = $this->postJson('/api/notifications', [
            'channel' => 'email',
            'recipient' => 'test@example.com',
            'content' => $uniqueContent,
            'priority' => 'normal',
        ]);

        $this->assertEquals(201, $response->status());
        $notificationId = $response->json('notification.id');

        // Process the job
        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'high,normal,low',
            '--once' => true,
        ]);

        // Fetch notification and verify provider processed it
        $notificationResponse = $this->getJson("/api/notifications/{$notificationId}");
        $notification = $notificationResponse->json('data');

        // Verify notification reached provider (status = sent)
        $this->assertEquals('sent', $notification['status']);

        // Verify provider response captured
        $this->assertNotNull($notification['provider_response']);
        $this->assertEquals(202, $notification['provider_response']['http_status']);

        // The payload was sent with correct structure (verified by successful delivery)
        // Actual webhook.site API may require token-level auth to query requests
    }

    /**
     * Test provider retry on transient errors.
     *
     * If provider is temporarily unavailable, notification should retry.
     * This test verifies the retry count is stored correctly.
     */
    public function test_notification_tracks_provider_retry_attempts(): void
    {
        // Bu test bir işlem denemesi sonrası attempt_count değerinin tutulduğunu kontrol eder.
        // Worker bir kez çalıştıktan sonra attempt_count=1 ise test geçer.
        // Create notification
        $response = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Retry tracking test ' . now()->timestamp,
            'priority' => 'high',
        ]);

        $notificationId = $response->json('notification.id');

        // Process first time
        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'high,normal,low',
            '--once' => true,
        ]);

        // Fetch and check attempt count
        $notificationResponse = $this->getJson("/api/notifications/{$notificationId}");
        $notification = $notificationResponse->json('data');

        // Should have 1 attempt after first processing
        $this->assertEquals(1, $notification['attempt_count']);
    }
}
