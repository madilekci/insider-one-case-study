<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Exceptions\PermanentProviderException;
use App\Services\Exceptions\TransientProviderException;
use App\Services\NotificationProviderClient;
use App\Services\Observability\Metrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public string $notificationId)
    {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(NotificationProviderClient $providerClient, Metrics $metrics): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if (! $notification) {
            return;
        }

        if ($notification->status === Notification::STATUS_CANCELLED) {
            return;
        }

        if (! in_array($notification->status, [Notification::STATUS_QUEUED, Notification::STATUS_PENDING], true)) {
            return;
        }

        if ($notification->scheduled_at?->isFuture()) {
            $notification->status = Notification::STATUS_PENDING;
            $notification->save();

            $this->release(max(1, now()->diffInSeconds($notification->scheduled_at)));

            return;
        }

        if (! $this->acquireChannelRateLimit($notification)) {
            $notification->attempt_count = $this->attempts();
            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = sprintf('Rate limit exceeded for channel: %s', $notification->channel);
            $notification->save();
            $metrics->incrementRateLimited($notification->channel);

            Log::info('notification.rate_limited', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->batch_id,
                'channel' => $notification->channel,
                'attempt' => $this->attempts(),
            ]);

            $this->release((int) config('notifications.rate_limit.release_seconds', 1));

            return;
        }

        $notification->status = Notification::STATUS_PROCESSING;
        $notification->attempt_count = $this->attempts();
        $notification->save();

        Log::info('notification.processing', [
            'notification_id' => $notification->id,
            'batch_id' => $notification->batch_id,
            'channel' => $notification->channel,
            'attempt' => $this->attempts(),
        ]);

        $startedAt = microtime(true);

        try {
            $providerResponse = $providerClient->send($notification);
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));

            $notification->status = Notification::STATUS_SENT;
            $notification->provider_response = $providerResponse;
            $notification->last_error = null;
            $notification->save();
            $metrics->incrementSent();

            Log::info('notification.sent', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->batch_id,
                'channel' => $notification->channel,
                'attempt' => $this->attempts(),
                'provider_message_id' => $providerResponse['messageId'] ?? null,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (PermanentProviderException $e) {
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));
            $metrics->incrementProviderPermanentFailure();
            $metrics->incrementFailed();

            $notification->status = Notification::STATUS_FAILED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            Log::warning('notification.failed.permanent', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->batch_id,
                'channel' => $notification->channel,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (TransientProviderException $e) {
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));
            $metrics->incrementProviderTransientFailure();
            $metrics->incrementRetry();

            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            Log::warning('notification.failed.transient', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->batch_id,
                'channel' => $notification->channel,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));
            $metrics->incrementProviderTransientFailure();
            $metrics->incrementRetry();

            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            Log::error('notification.failed.unexpected', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->batch_id,
                'channel' => $notification->channel,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function acquireChannelRateLimit(Notification $notification): bool
    {
        $limitPerSecond = (int) config('notifications.rate_limit.per_second', 100);

        if ($limitPerSecond < 1) {
            return false;
        }

        $bucketKey = sprintf(
            'notifications:channel:%s:%s',
            $notification->channel,
            now()->format('YmdHis')
        );

        if (RateLimiter::tooManyAttempts($bucketKey, $limitPerSecond)) {
            return false;
        }

        RateLimiter::hit($bucketKey, 1);

        return true;
    }

    public function failed(Throwable $exception): void
    {
        app(Metrics::class)->incrementFailed();

        $notification = Notification::query()->find($this->notificationId);

        if (! $notification) {
            return;
        }

        if (in_array($notification->status, [Notification::STATUS_SENT, Notification::STATUS_CANCELLED], true)) {
            return;
        }

        $notification->status = Notification::STATUS_FAILED;
        $notification->last_error = $exception->getMessage();
        $notification->attempt_count = max($notification->attempt_count, $this->attempts());
        $notification->save();
    }
}
