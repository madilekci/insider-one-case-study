<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Exceptions\PermanentProviderException;
use App\Services\Exceptions\TransientProviderException;
use App\Services\NotificationProviderClient;
use App\Services\Observability\EventLog;
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

    public function handle(
        NotificationProviderClient $providerClient,
        Metrics $metrics,
        ?EventLog $eventLog = null,
    ): void
    {
        $eventLog ??= app(EventLog::class);

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

            $rateLimitCtx = [
                'notification_id' => $notification->id,
                'batch_id'        => $notification->batch_id,
                'channel'         => $notification->channel,
                'attempt'         => $this->attempts(),
                'message'         => 'Rate limit exceeded',
            ];
            Log::info('notification.rate_limited', $rateLimitCtx);
            $eventLog->write('info', 'notification.rate_limited', $rateLimitCtx);

            $this->release((int) config('notifications.rate_limit.release_seconds', 1));

            return;
        }

        $notification->status = Notification::STATUS_PROCESSING;
        $notification->attempt_count = $this->attempts();
        $notification->save();

        $processingCtx = [
            'notification_id' => $notification->id,
            'batch_id'        => $notification->batch_id,
            'channel'         => $notification->channel,
            'attempt'         => $this->attempts(),
            'message'         => 'Processing notification',
        ];
        Log::info('notification.processing', $processingCtx);
        $eventLog->write('info', 'notification.processing', $processingCtx);

        $startedAt = microtime(true);

        try {
            $providerResponse = $providerClient->send($notification);
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));

            $notification->status = Notification::STATUS_SENT;
            $notification->provider_response = $providerResponse;
            $notification->last_error = null;
            $notification->save();
            $metrics->incrementSent();

            $sentCtx = [
                'notification_id'     => $notification->id,
                'batch_id'            => $notification->batch_id,
                'channel'             => $notification->channel,
                'attempt'             => $this->attempts(),
                'provider_message_id' => $providerResponse['messageId'] ?? null,
                'latency_ms'          => (int) round((microtime(true) - $startedAt) * 1000),
                'message'             => 'Notification sent successfully',
            ];
            Log::info('notification.sent', $sentCtx);
            $eventLog->write('info', 'notification.sent', $sentCtx);
        } catch (PermanentProviderException $e) {
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));
            $metrics->incrementProviderPermanentFailure();
            $metrics->incrementFailed();

            $notification->status = Notification::STATUS_FAILED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            $permFailCtx = [
                'notification_id' => $notification->id,
                'batch_id'        => $notification->batch_id,
                'channel'         => $notification->channel,
                'attempt'         => $this->attempts(),
                'error'           => $e->getMessage(),
                'message'         => 'Permanent provider failure',
            ];
            Log::warning('notification.failed.permanent', $permFailCtx);
            $eventLog->write('error', 'notification.failed.permanent', $permFailCtx);

            return;
        } catch (TransientProviderException $e) {
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));
            $metrics->incrementProviderTransientFailure();
            $metrics->incrementRetry();

            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            $transFailCtx = [
                'notification_id' => $notification->id,
                'batch_id'        => $notification->batch_id,
                'channel'         => $notification->channel,
                'attempt'         => $this->attempts(),
                'error'           => $e->getMessage(),
                'message'         => 'Transient failure — will retry',
            ];
            Log::warning('notification.failed.transient', $transFailCtx);
            $eventLog->write('warning', 'notification.failed.transient', $transFailCtx);

            throw $e;
        } catch (Throwable $e) {
            $metrics->observeProviderRequest((int) round((microtime(true) - $startedAt) * 1000));
            $metrics->incrementProviderTransientFailure();
            $metrics->incrementRetry();

            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            $unexpectedCtx = [
                'notification_id' => $notification->id,
                'batch_id'        => $notification->batch_id,
                'channel'         => $notification->channel,
                'attempt'         => $this->attempts(),
                'error'           => $e->getMessage(),
                'message'         => 'Unexpected failure — will retry',
            ];
            Log::error('notification.failed.unexpected', $unexpectedCtx);
            $eventLog->write('error', 'notification.failed.unexpected', $unexpectedCtx);

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
