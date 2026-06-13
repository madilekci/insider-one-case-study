<?php

namespace App\Services\Observability;

use App\Models\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

class Metrics
{
    private const KEY_CREATED_TOTAL = 'metrics:notifications:created_total';
    private const KEY_CANCELLED_TOTAL = 'metrics:notifications:cancelled_total';
    private const KEY_SENT_TOTAL = 'metrics:notifications:sent_total';
    private const KEY_FAILED_TOTAL = 'metrics:notifications:failed_total';
    private const KEY_RATE_LIMITED_TOTAL = 'metrics:notifications:rate_limited_total';
    private const KEY_RETRY_TOTAL = 'metrics:notifications:retry_total';
    private const KEY_PROVIDER_TRANSIENT_FAILURE_TOTAL = 'metrics:provider:transient_failure_total';
    private const KEY_PROVIDER_PERMANENT_FAILURE_TOTAL = 'metrics:provider:permanent_failure_total';
    private const KEY_PROVIDER_REQUEST_TOTAL = 'metrics:provider:request_total';
    private const KEY_PROVIDER_LATENCY_SUM_MS = 'metrics:provider:latency_sum_ms';
    private const KEY_PROVIDER_LATENCY_COUNT = 'metrics:provider:latency_count';

    public function incrementCreated(string $channel): void
    {
        $this->increment(self::KEY_CREATED_TOTAL);
        $this->increment($this->channelCounterKey('created', $channel));
    }

    public function incrementCancelled(): void
    {
        $this->increment(self::KEY_CANCELLED_TOTAL);
    }

    public function incrementSent(): void
    {
        $this->increment(self::KEY_SENT_TOTAL);
    }

    public function incrementFailed(): void
    {
        $this->increment(self::KEY_FAILED_TOTAL);
    }

    public function incrementRateLimited(string $channel): void
    {
        $this->increment(self::KEY_RATE_LIMITED_TOTAL);
        $this->increment($this->channelCounterKey('rate_limited', $channel));
    }

    public function incrementRetry(): void
    {
        $this->increment(self::KEY_RETRY_TOTAL);
    }

    public function incrementProviderTransientFailure(): void
    {
        $this->increment(self::KEY_PROVIDER_TRANSIENT_FAILURE_TOTAL);
    }

    public function incrementProviderPermanentFailure(): void
    {
        $this->increment(self::KEY_PROVIDER_PERMANENT_FAILURE_TOTAL);
    }

    public function observeProviderRequest(int $latencyMs): void
    {
        $this->increment(self::KEY_PROVIDER_REQUEST_TOTAL);
        $this->increment(self::KEY_PROVIDER_LATENCY_SUM_MS, $latencyMs);
        $this->increment(self::KEY_PROVIDER_LATENCY_COUNT);
    }

    public function resetOperationalCounters(): void
    {
        $keys = [
            self::KEY_CREATED_TOTAL,
            self::KEY_CANCELLED_TOTAL,
            self::KEY_SENT_TOTAL,
            self::KEY_FAILED_TOTAL,
            self::KEY_RATE_LIMITED_TOTAL,
            self::KEY_RETRY_TOTAL,
            self::KEY_PROVIDER_TRANSIENT_FAILURE_TOTAL,
            self::KEY_PROVIDER_PERMANENT_FAILURE_TOTAL,
            self::KEY_PROVIDER_REQUEST_TOTAL,
            self::KEY_PROVIDER_LATENCY_SUM_MS,
            self::KEY_PROVIDER_LATENCY_COUNT,
        ];

        foreach (Notification::channels() as $channel) {
            $keys[] = $this->channelCounterKey('created', $channel);
            $keys[] = $this->channelCounterKey('rate_limited', $channel);
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $notificationTotals = $this->notificationTotals();
        $overview = $this->overviewTotals();
        $latencyCount = $this->getInt(self::KEY_PROVIDER_LATENCY_COUNT);
        $latencySumMs = $this->getInt(self::KEY_PROVIDER_LATENCY_SUM_MS);

        $sentTotal = $notificationTotals['sent_total'];
        $failedTotal = $notificationTotals['failed_total'];
        $processedTotal = $sentTotal + $failedTotal;

        return [
            'notifications' => [
                'created_total' => $notificationTotals['created_total'],
                'cancelled_total' => $notificationTotals['cancelled_total'],
                'sent_total' => $sentTotal,
                'failed_total' => $failedTotal,
                'overview' => $overview,
                'retry_total' => $this->getInt(self::KEY_RETRY_TOTAL),
                'rate_limited_total' => $this->getInt(self::KEY_RATE_LIMITED_TOTAL),
                'success_rate' => $processedTotal > 0 ? round($sentTotal / $processedTotal, 4) : null,
                'failure_rate' => $processedTotal > 0 ? round($failedTotal / $processedTotal, 4) : null,
                'created_by_channel' => $notificationTotals['created_by_channel'],
                'rate_limited_by_channel' => $this->channelMap('rate_limited'),
            ],
            'provider' => [
                'request_total' => $this->getInt(self::KEY_PROVIDER_REQUEST_TOTAL),
                'transient_failure_total' => $this->getInt(self::KEY_PROVIDER_TRANSIENT_FAILURE_TOTAL),
                'permanent_failure_total' => $this->getInt(self::KEY_PROVIDER_PERMANENT_FAILURE_TOTAL),
                'avg_latency_ms' => $latencyCount > 0 ? (int) round($latencySumMs / $latencyCount) : 0,
            ],
            'queues' => [
                'high' => $this->queueDepth('high'),
                'normal' => $this->queueDepth('normal'),
                'low' => $this->queueDepth('low'),
            ],
        ];
    }

    private function increment(string $key, int $value = 1): void
    {
        Cache::add($key, 0, now()->addDays(7));
        Cache::increment($key, $value);
    }

    private function getInt(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    /**
     * @return array{created_total: int, cancelled_total: int, sent_total: int, failed_total: int, created_by_channel: array<string, int>}
     */
    private function notificationTotals(): array
    {
        $channelTotals = array_fill_keys(Notification::channels(), 0);

        try {
            $createdByChannel = Notification::query()
                ->selectRaw('channel, count(*) as total')
                ->groupBy('channel')
                ->pluck('total', 'channel')
                ->map(fn (mixed $count): int => (int) $count)
                ->all();

            return [
                'created_total' => (int) Notification::query()->count(),
                'cancelled_total' => (int) Notification::query()->where('status', Notification::STATUS_CANCELLED)->count(),
                'sent_total' => (int) Notification::query()->where('status', Notification::STATUS_SENT)->count(),
                'failed_total' => (int) Notification::query()->where('status', Notification::STATUS_FAILED)->count(),
                'created_by_channel' => array_replace($channelTotals, $createdByChannel),
            ];
        } catch (Throwable) {
            return [
                'created_total' => 0,
                'cancelled_total' => 0,
                'sent_total' => 0,
                'failed_total' => 0,
                'created_by_channel' => $channelTotals,
            ];
        }
    }

    /**
     * @return array<string, int>
     */
    private function overviewTotals(): array
    {
        try {
            $statusCounts = Notification::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn (mixed $count): int => (int) $count)
                ->all();

            $queuedNew = (int) Notification::query()
                ->where('status', Notification::STATUS_QUEUED)
                ->where('attempt_count', 0)
                ->count();

            $queuedRetry = (int) Notification::query()
                ->where('status', Notification::STATUS_QUEUED)
                ->where('attempt_count', '>', 0)
                ->count();

            $sent = (int) ($statusCounts[Notification::STATUS_SENT] ?? 0);
            $failed = (int) ($statusCounts[Notification::STATUS_FAILED] ?? 0);
            $queued = (int) ($statusCounts[Notification::STATUS_QUEUED] ?? 0);
            $pending = (int) ($statusCounts[Notification::STATUS_PENDING] ?? 0);
            $processing = (int) ($statusCounts[Notification::STATUS_PROCESSING] ?? 0);

            return [
                'total' => (int) Notification::query()->count(),
                'processed_total' => $sent + $failed,
                'processed_sent' => $sent,
                'processed_failed' => $failed,
                'waiting_total' => $queued + $pending + $processing,
                'waiting_retry' => $queuedRetry,
                'waiting_new' => $queuedNew + $pending,
                'in_queue_total' => $queued,
                'in_queue_retry' => $queuedRetry,
                'in_queue_new' => $queuedNew,
                'scheduled_total' => $pending,
                'processing_total' => $processing,
            ];
        } catch (Throwable) {
            return [
                'total' => 0,
                'processed_total' => 0,
                'processed_sent' => 0,
                'processed_failed' => 0,
                'waiting_total' => 0,
                'waiting_retry' => 0,
                'waiting_new' => 0,
                'in_queue_total' => 0,
                'in_queue_retry' => 0,
                'in_queue_new' => 0,
                'scheduled_total' => 0,
                'processing_total' => 0,
            ];
        }
    }

    /**
     * @return array<string, int>
     */
    private function channelMap(string $metric): array
    {
        $channels = [];

        foreach (Notification::channels() as $channel) {
            $channels[$channel] = $this->getInt($this->channelCounterKey($metric, $channel));
        }

        return $channels;
    }

    private function channelCounterKey(string $metric, string $channel): string
    {
        return sprintf('metrics:notifications:%s:%s', $metric, $channel);
    }

    private function queueDepth(string $queue): int
    {
        try {
            return (int) Redis::connection()->llen(sprintf('queues:%s', $queue));
        } catch (Throwable) {
            return -1;
        }
    }
}