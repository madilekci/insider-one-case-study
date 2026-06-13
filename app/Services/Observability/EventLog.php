<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Redis;
use Throwable;

class EventLog
{
    private const KEY = 'eventlog:entries';
    private const RECENT_LIMIT = 100;
    private const RETENTION_LIMIT = 5000;

    public function write(string $level, string $event, array $context = []): void
    {
        try {
            $entry = array_merge([
                'timestamp'      => now()->toIso8601String(),
                'level'          => $level,
                'event'          => $event,
                'notification_id' => null,
                'batch_id'       => null,
                'channel'        => null,
                'attempt'        => null,
                'correlation_id' => null,
                'message'        => $event,
            ], $context);

            $redis = Redis::connection();
            $redis->lpush(self::KEY, json_encode($entry));
            $redis->ltrim(self::KEY, 0, self::RETENTION_LIMIT - 1);
        } catch (Throwable) {
            // fail silently — observability must not break the main flow
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(): array
    {
        try {
            $entries = Redis::connection()->lrange(self::KEY, 0, self::RECENT_LIMIT - 1);

            return $this->decodeEntries($entries);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function retained(): array
    {
        try {
            $entries = Redis::connection()->lrange(self::KEY, 0, self::RETENTION_LIMIT - 1);

            return $this->decodeEntries($entries);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, string> $entries
     * @return array<int, array<string, mixed>>
     */
    private function decodeEntries(array $entries): array
    {
        $decoded = [];

        foreach ($entries as $entry) {
            $item = json_decode($entry, true);

            if (is_array($item)) {
                $decoded[] = $item;
            }
        }

        return $decoded;
    }

    public function clear(): void
    {
        try {
            Redis::connection()->del(self::KEY);
        } catch (Throwable) {
            // fail silently — clearing observability state should not break flows
        }
    }
}
