<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_metrics_endpoint_returns_expected_shape(): void
    {
        // Bu test metrics endpoint'inin sözleşme/alan yapısını doğrular.
        // Beklenen ana başlıklar ve alt alanlar geliyorsa test geçer.
        $response = $this->getJson('/metrics');

        $response->assertOk()
            ->assertJsonStructure([
                'notifications' => [
                    'created_total',
                    'cancelled_total',
                    'sent_total',
                    'failed_total',
                    'retry_total',
                    'rate_limited_total',
                    'success_rate',
                    'failure_rate',
                    'created_by_channel' => ['sms', 'email', 'push'],
                    'rate_limited_by_channel' => ['sms', 'email', 'push'],
                ],
                'provider' => [
                    'request_total',
                    'transient_failure_total',
                    'permanent_failure_total',
                    'avg_latency_ms',
                ],
                'queues' => ['high', 'normal', 'low'],
            ]);
    }

    public function test_metrics_increments_after_notification_creation(): void
    {
        // Bu test create çağrısından sonra metrics sayaçlarının arttığını kontrol eder.
        // created_total ve kanal bazlı sayaçlar beklenen değerlere çıkarsa test geçer.
        $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Metrics test item',
            'priority' => 'high',
        ])->assertCreated();

        $metrics = $this->getJson('/metrics');

        $metrics->assertOk()
            ->assertJsonPath('notifications.created_total', 1)
            ->assertJsonPath('notifications.created_by_channel.sms', 1)
            ->assertJsonPath('notifications.created_by_channel.email', 0)
            ->assertJsonPath('notifications.created_by_channel.push', 0);
    }
}
