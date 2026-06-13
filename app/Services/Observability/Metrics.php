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

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $latencyCount = $this->getInt(self::KEY_PROVIDER_LATENCY_COUNT);
        $latencySumMs = $this->getInt(self::KEY_PROVIDER_LATENCY_SUM_MS);

        return [
            'notifications' => [
                'created_total' => $this->getInt(self::KEY_CREATED_TOTAL),
                'cancelled_total' => $this->getInt(self::KEY_CANCELLED_TOTAL),
                'sent_total' => $this->getInt(self::KEY_SENT_TOTAL),
                'failed_total' => $this->getInt(self::KEY_FAILED_TOTAL),
                'retry_total' => $this->getInt(self::KEY_RETRY_TOTAL),
                'rate_limited_total' => $this->getInt(self::KEY_RATE_LIMITED_TOTAL),
                'created_by_channel' => $this->channelMap('created'),
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