<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Exceptions\PermanentProviderException;
use App\Services\Exceptions\TransientProviderException;
use App\Services\NotificationProviderClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(NotificationProviderClient $providerClient): void
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

        if (! $this->acquireChannelRateLimit($notification)) {
            $notification->attempt_count = $this->attempts();
            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = sprintf('Rate limit exceeded for channel: %s', $notification->channel);
            $notification->save();

            $this->release((int) config('notifications.rate_limit.release_seconds', 1));

            return;
        }

        $notification->status = Notification::STATUS_PROCESSING;
        $notification->attempt_count = $this->attempts();
        $notification->save();

        try {
            $providerResponse = $providerClient->send($notification);

            $notification->status = Notification::STATUS_SENT;
            $notification->provider_response = $providerResponse;
            $notification->last_error = null;
            $notification->save();
        } catch (PermanentProviderException $e) {
            $notification->status = Notification::STATUS_FAILED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            return;
        } catch (TransientProviderException $e) {
            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = $e->getMessage();
            $notification->save();

            throw $e;
        } catch (Throwable $e) {
            $notification->status = Notification::STATUS_QUEUED;
            $notification->last_error = $e->getMessage();
            $notification->save();

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
