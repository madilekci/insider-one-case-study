<?php

namespace Tests\Feature;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\NotificationProviderClient;
use App\Services\Observability\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HighThroughputLoadTest extends TestCase
{
    use RefreshDatabase;

    private const BURST_NOTIFICATION_COUNT = 3000;
    private const MAX_BATCH_SIZE = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('RUN_LOAD_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Load tests are disabled. Set RUN_LOAD_TESTS=true to enable.');
        }

        config()->set('notifications.provider.webhook_url', 'https://example.test/provider');
        config()->set('notifications.rate_limit.per_second', 1_000_000);

        Http::fake([
            'https://example.test/provider' => Http::response([
                'messageId' => 'load-test-provider-msg',
                'status' => 'accepted',
                'timestamp' => now()->toISOString(),
            ], 202),
        ]);

    }

    public function test_api_accepts_total_burst_via_chunked_batches(): void
    {
        // Bu test toplam yükü API limitine takılmadan (parçalı batch) gönderir.
        // Tüm istekler başarılı olup toplam kayıt ve dispatch sayısı hedefe ulaşıyorsa test geçer.
        Queue::fake();

        $startedAt = microtime(true);

        $createdTotal = $this->postInBatchChunks(self::BURST_NOTIFICATION_COUNT);

        $requestDurationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertSame(self::BURST_NOTIFICATION_COUNT, $createdTotal);
        $this->assertDatabaseCount('notifications', self::BURST_NOTIFICATION_COUNT);
        Queue::assertPushed(ProcessNotificationJob::class, self::BURST_NOTIFICATION_COUNT);

        // Keep threshold generous to avoid machine-dependent flakes while still surfacing severe regressions.
        $this->assertLessThan(
            30_000,
            $requestDurationMs,
            "Burst API request took too long: {$requestDurationMs}ms"
        );
    }

    public function test_worker_drains_total_burst_with_reasonable_throughput(): void
    {
        // Bu test queued durumundaki çok sayıda bildirimi job ile işler.
        // Tüm kayıtlar sent'e dönüyor ve işlem süresi makul eşik altında kalıyorsa test geçer.
        $notifications = [];

        for ($i = 0; $i < self::BURST_NOTIFICATION_COUNT; $i++) {
            $notifications[] = Notification::create([
                'channel' => match ($i % 3) {
                    0 => 'sms',
                    1 => 'email',
                    default => 'push',
                },
                'recipient' => match ($i % 3) {
                    0 => '+90555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    1 => "load+{$i}@example.com",
                    default => "device-token-{$i}",
                },
                'content' => "load-processing-message-{$i}",
                'priority' => match ($i % 3) {
                    0 => 'high',
                    1 => 'normal',
                    default => 'low',
                },
                'status' => Notification::STATUS_QUEUED,
                'idempotency_key' => "load-processing-{$i}",
            ]);
        }

        $startedAt = microtime(true);

        foreach ($notifications as $notification) {
            (new ProcessNotificationJob($notification->id))->handle(
                app(NotificationProviderClient::class),
                app(Metrics::class)
            );
        }

        $processingDurationSec = microtime(true) - $startedAt;

        $this->assertDatabaseCount('notifications', self::BURST_NOTIFICATION_COUNT);
        $this->assertSame(self::BURST_NOTIFICATION_COUNT, Notification::query()->where('status', Notification::STATUS_SENT)->count());

        $throughput = (int) floor(self::BURST_NOTIFICATION_COUNT / max($processingDurationSec, 0.001));

        $this->assertLessThan(
            90,
            $processingDurationSec,
            sprintf(
                'Worker took too long to drain queue: %.2fs (%d notif/s)',
                $processingDurationSec,
                $throughput
            )
        );
    }

    public function test_api_handles_rapid_fire_single_requests(): void
    {
        // Bu test API'ye kısa sürede art arda tekil create isteği yollar.
        // Her istek 201 dönüyor, beklenen kayıt/job sayısı oluşuyor ve süre eşiği aşılmıyorsa test geçer.
        Queue::fake();

        $requestCount = 300;
        $startedAt = microtime(true);

        for ($i = 0; $i < $requestCount; $i++) {
            $channel = match ($i % 3) {
                0 => 'sms',
                1 => 'email',
                default => 'push',
            };

            $recipient = match ($channel) {
                'sms' => '+90555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'email' => "rapid+{$i}@example.com",
                default => "rapid-device-{$i}",
            };

            $this->postJson('/api/notifications', [
                'channel' => $channel,
                'recipient' => $recipient,
                'content' => "rapid-fire-message-{$i}",
                'priority' => 'normal',
            ])->assertCreated();
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertDatabaseCount('notifications', $requestCount);
        Queue::assertPushed(ProcessNotificationJob::class, $requestCount);

        $this->assertLessThan(
            30_000,
            $durationMs,
            "Rapid-fire single requests took too long: {$durationMs}ms"
        );
    }

    public function test_api_handles_repeated_batch_bursts_sequentially(): void
    {
        // Bu test ardışık çoklu batch create akışını dener.
        // Her batch başarılı dönüyor, toplam kayıt/job sayısı doğruysa ve süre uygunsa test geçer.
        Queue::fake();

        $batchCount = 10;
        $batchSize = 100;
        $expectedTotal = $batchCount * $batchSize;
        $startedAt = microtime(true);

        for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {
            $response = $this->postJson('/api/notifications', [
                'notifications' => $this->makeBatchPayload($batchSize, $batchIndex * $batchSize),
            ]);

            $response->assertCreated()->assertJsonPath('count', $batchSize);
        }

        $durationSec = microtime(true) - $startedAt;

        $this->assertDatabaseCount('notifications', $expectedTotal);
        Queue::assertPushed(ProcessNotificationJob::class, $expectedTotal);

        $this->assertLessThan(
            45,
            $durationSec,
            sprintf('Sequential batch bursts took too long: %.2fs', $durationSec)
        );
    }

    public function test_idempotency_replay_under_load_does_not_create_duplicates(): void
    {
        // Bu test aynı idempotency anahtarlarıyla tekrar istek atıldığında duplicate oluşmamasını doğrular.
        // İlk dalga 201, tekrar dalgası 200 döner; toplam kayıt/job sayısı ilk dalga kadar kalıyorsa test geçer.
        Queue::fake();

        $requestCount = 200;

        $startedAt = microtime(true);

        for ($i = 0; $i < $requestCount; $i++) {
            $this->withHeaders([
                'Idempotency-Key' => "load-idempotency-{$i}",
            ])->postJson('/api/notifications', [
                'channel' => 'sms',
                'recipient' => '+90555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'content' => "idempotency-first-pass-{$i}",
                'priority' => 'high',
            ])->assertCreated();
        }

        for ($i = 0; $i < $requestCount; $i++) {
            $this->withHeaders([
                'Idempotency-Key' => "load-idempotency-{$i}",
            ])->postJson('/api/notifications', [
                'channel' => 'sms',
                'recipient' => '+905559999999',
                'content' => 'idempotency-replay',
                'priority' => 'low',
            ])->assertOk();
        }

        $durationSec = microtime(true) - $startedAt;

        $this->assertDatabaseCount('notifications', $requestCount);
        Queue::assertPushed(ProcessNotificationJob::class, $requestCount);

        $this->assertLessThan(
            30,
            $durationSec,
            sprintf('Idempotency replay load scenario took too long: %.2fs', $durationSec)
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function makeBatchPayload(int $count, int $offset = 0): array
    {
        $items = [];
        $priorities = ['high', 'normal', 'low'];
        $channels = ['sms', 'email', 'push'];

        for ($i = 0; $i < $count; $i++) {
            $index = $i + $offset;

            $items[] = [
                'channel' => $channels[$index % 3],
                'recipient' => match ($channels[$index % 3]) {
                    'sms' => '+90555'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
                    'email' => "load+{$index}@example.com",
                    default => "device-token-{$index}",
                },
                'content' => "load-test-message-{$index}",
                'priority' => $priorities[$index % 3],
                'idempotency_key' => "load-batch-{$index}",
            ];
        }

        return $items;
    }

    private function postInBatchChunks(int $total): int
    {
        $createdTotal = 0;
        $offset = 0;

        while ($offset < $total) {
            $chunkSize = min(self::MAX_BATCH_SIZE, $total - $offset);

            $response = $this->postJson('/api/notifications', [
                'notifications' => $this->makeBatchPayload($chunkSize, $offset),
            ]);

            $response->assertCreated()->assertJsonPath('count', $chunkSize);

            $createdTotal += $chunkSize;
            $offset += $chunkSize;
        }

        return $createdTotal;
    }
}
