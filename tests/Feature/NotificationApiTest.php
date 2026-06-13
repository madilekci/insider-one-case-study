<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_fetches_notification_by_id(): void
    {
        // Bu test var olan bir kaydı ID ile okuma endpoint'ine gider.
        // 200 dönüp doğru ID ve kanal bilgisi gelirse test geçer.
        $notification = Notification::create([
            'channel' => 'push',
            'recipient' => 'device-token-1',
            'content' => 'push-test',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        $this->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.channel', 'push');
    }

    public function test_it_creates_single_notification(): void
    {
        // Bu test tekil bildirim create isteği atar.
        // 201 dönmesi, response alanlarının doğru olması ve DB'de 1 kayıt oluşması beklenir.
        $response = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Hello from test',
            'priority' => 'high',
        ]);

        $response->assertCreated()
            ->assertJsonPath('notification.channel', 'sms')
            ->assertJsonPath('notification.status', 'queued')
            ->assertJsonPath('notification.priority', 'high');

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_it_creates_batch_and_fetches_by_batch_id(): void
    {
        // Bu test batch create sonrası dönen batch_id ile batch sorgulama yapar.
        // Oluşturma ve sorgulama adımları başarılıysa test geçer.
        $response = $this->postJson('/api/notifications', [
            'notifications' => [
                [
                    'channel' => 'sms',
                    'recipient' => '+905551234567',
                    'content' => 'batch-1',
                    'priority' => 'normal',
                ],
                [
                    'channel' => 'email',
                    'recipient' => 'test@example.com',
                    'content' => 'batch-2',
                    'priority' => 'low',
                ],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('count', 2);

        $batchId = $response->json('batch_id');

        $this->getJson("/api/batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('batch_id', $batchId)
            ->assertJsonPath('count', 2);
    }

    public function test_it_returns_404_for_missing_batch_id(): void
    {
        // Bu test olmayan bir batch_id ile sorgu atar.
        // 404 ve "Batch not found." mesajı gelirse test geçer.
        $this->getJson('/api/batches/2d0473f5-5ccb-4146-b857-112ec95a1a93')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Batch not found.');
    }

    public function test_it_lists_with_filters_and_cancels_when_queued(): void
    {
        // Bu test önce filtreli liste endpoint'ini sonra queued kaydı cancel etmeyi dener.
        // Filtre sonucu doğru kayıt dönüp cancel sonrası status cancelled olursa test geçer.
        $queued = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'queued-item',
            'priority' => 'normal',
            'status' => Notification::STATUS_QUEUED,
        ]);

        Notification::create([
            'channel' => 'email',
            'recipient' => 'done@example.com',
            'content' => 'sent-item',
            'priority' => 'low',
            'status' => Notification::STATUS_SENT,
        ]);

        $this->getJson('/api/notifications?status=queued&channel=sms&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $queued->id);

        $this->postJson("/api/notifications/{$queued->id}/cancel")
            ->assertOk()
            ->assertJsonPath('notification.status', Notification::STATUS_CANCELLED);
    }

    public function test_it_cancels_pending_notification(): void
    {
        // Bu test pending bir bildirimin iptal edilebildiğini doğrular.
        // Cancel çağrısı sonrası status cancelled olursa test geçer.
        $pending = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'pending-item',
            'priority' => 'normal',
            'status' => Notification::STATUS_PENDING,
        ]);

        $this->postJson("/api/notifications/{$pending->id}/cancel")
            ->assertOk()
            ->assertJsonPath('notification.status', Notification::STATUS_CANCELLED);
    }

    public function test_it_rejects_cancel_when_not_queued_or_pending(): void
    {
        // Bu test sent durumundaki bir kaydı cancel etmeyi dener.
        // İş kuralı gereği 422 ve ilgili hata mesajı dönmesi beklenir.
        $sent = Notification::create([
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'sent-item',
            'priority' => 'normal',
            'status' => Notification::STATUS_SENT,
        ]);

        $this->postJson("/api/notifications/{$sent->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending or queued notifications can be cancelled.');
    }

    public function test_it_rejects_mixed_single_and_batch_payload(): void
    {
        // Bu test aynı payload içinde hem single hem batch alanı göndermeyi dener.
        // Validation 422 dönüp notifications alanında hata verirse test geçer.
        $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'single',
            'notifications' => [
                [
                    'channel' => 'sms',
                    'recipient' => '+905551200001',
                    'content' => 'batch-item',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('notifications');
    }

    public function test_it_rejects_batch_larger_than_1000(): void
    {
        // Bu test API limitini aşan (1001) batch gönderir.
        // Validation 422 dönerek limitin korunduğunu gösterirse test geçer.
        $items = [];

        for ($i = 0; $i < 1001; $i++) {
            $items[] = [
                'channel' => 'sms',
                'recipient' => "+90555123{$i}",
                'content' => 'bulk-item',
            ];
        }

        $this->postJson('/api/notifications', [
            'notifications' => $items,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('notifications');
    }

    public function test_it_enforces_channel_content_limit(): void
    {
        // Bu test sms kanalına izin verilenden uzun içerik gönderir.
        // 422 ve content alanında validation hatası gelirse test geçer.
        $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => str_repeat('x', 161),
            'priority' => 'normal',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('content');
    }

    public function test_it_validates_list_query_params(): void
    {
        // Bu test geçersiz query parametreleriyle listeleme endpoint'ini çağırır.
        // 422 ve ilgili parametrelerde hata dönmesi beklenir.
        $this->getJson('/api/notifications?status=unknown&channel=fax&per_page=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'channel', 'per_page']);
    }

    public function test_it_filters_by_date_range(): void
    {
        // Bu test eski ve yeni kayıt üretip tarih aralığı filtresini dener.
        // Sadece aralık içindeki kayıt dönüyorsa test geçer.
        Notification::unguarded(function (): void {
            Notification::create([
                'id' => 'd417db84-4ecf-4e29-8780-1177f39f70a1',
                'channel' => 'sms',
                'recipient' => '+905551234111',
                'content' => 'old-item',
                'priority' => 'normal',
                'status' => Notification::STATUS_QUEUED,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ]);

            Notification::create([
                'id' => 'b17c84c0-d41f-40f9-af23-c5f4ad0fc056',
                'channel' => 'sms',
                'recipient' => '+905551234222',
                'content' => 'new-item',
                'priority' => 'normal',
                'status' => Notification::STATUS_QUEUED,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        });

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/notifications?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'new-item');
    }
}
