<?php

namespace App\Services;

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\Observability\EventLog;
use App\Services\Observability\Metrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class DemoTrafficService
{
    private const ACTIVE_RUN_KEY = 'dashboard:demo:active_run_id';
    private const LAST_RUN_KEY = 'dashboard:demo:last_run_id';
    private const RUN_KEY_PREFIX = 'dashboard:demo:run:';
    private const CANCEL_KEY_PREFIX = 'dashboard:demo:cancel:';
    private const IDP_PREFIX = 'demo-traffic-';
    private const STATUS_TTL_MINUTES = 240;

    public function __construct(
        private EventLog $eventLog,
        private Metrics $metrics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function initializeRun(string $runId, int $durationSeconds, string $scriptFile): array
    {
        $payload = [
            'run_id' => $runId,
            'is_running' => true,
            'started_at' => now()->toIso8601String(),
            'duration_seconds' => $durationSeconds,
            'script_file' => $scriptFile,
            'created' => 0,
            'queued' => 0,
            'scheduled' => 0,
            'cancelled' => 0,
            'failed_seeded' => 0,
            'sent_seeded' => 0,
            'deleted' => 0,
            'message' => 'Demo traffic seeding queued',
            'finished_at' => null,
        ];

        $this->putRun($runId, $payload);
        Cache::put(self::ACTIVE_RUN_KEY, $runId, now()->addMinutes(self::STATUS_TTL_MINUTES));
        Cache::put(self::LAST_RUN_KEY, $runId, now()->addMinutes(self::STATUS_TTL_MINUTES));
        Cache::forget($this->cancelKey($runId));

        return $payload;
    }

    public function run(string $runId, int $durationSeconds = 60): void
    {
        $status = $this->getRun($runId);

        if (! $status) {
            return;
        }

        $deadline = microtime(true) + $durationSeconds;
        $tick = 0;

        $this->eventLog->write('info', 'dashboard.demo.started', [
            'message' => sprintf('Demo traffic started for %d seconds', $durationSeconds),
            'demo_run_id' => $runId,
        ]);

        while (microtime(true) < $deadline) {
            if ($this->shouldCancel($runId)) {
                $status['message'] = 'Demo traffic cancelled';
                break;
            }

            $burstSize = random_int(12, 28);
            $created = 0;
            $queued = 0;
            $scheduled = 0;
            $cancelled = 0;
            $failedSeeded = 0;
            $sentSeeded = 0;

            for ($i = 0; $i < $burstSize; $i++) {
                $notification = $this->makeNotification($runId, $tick, $i);
                $created++;

                switch ($notification->status) {
                    case Notification::STATUS_QUEUED:
                        $queued++;
                        $this->dispatchForProcessing($notification);
                        break;
                    case Notification::STATUS_PENDING:
                        $scheduled++;
                        $this->dispatchForProcessing($notification);
                        break;
                    case Notification::STATUS_CANCELLED:
                        $cancelled++;
                        break;
                    case Notification::STATUS_FAILED:
                        $failedSeeded++;
                        break;
                    case Notification::STATUS_SENT:
                        $sentSeeded++;
                        break;
                }
            }

            $status = $this->getRun($runId) ?? $status;
            $status['created'] += $created;
            $status['queued'] += $queued;
            $status['scheduled'] += $scheduled;
            $status['cancelled'] += $cancelled;
            $status['failed_seeded'] += $failedSeeded;
            $status['sent_seeded'] += $sentSeeded;
            $status['message'] = sprintf('Generated burst %d with %d notifications', $tick + 1, $burstSize);
            $this->putRun($runId, $status);

            $tick++;
            usleep(random_int(700_000, 1_100_000));
        }

        $status = $this->getRun($runId) ?? $status;
        $status['is_running'] = false;
        $status['finished_at'] = now()->toIso8601String();
        $status['message'] = $status['message'] === 'Demo traffic cancelled'
            ? $status['message']
            : sprintf('Demo traffic finished after %d bursts', $tick);
        $this->putRun($runId, $status);
        Cache::forget(self::ACTIVE_RUN_KEY);
        Cache::forget($this->cancelKey($runId));

        $this->eventLog->write('info', 'dashboard.demo.finished', [
            'message' => $status['message'],
            'demo_run_id' => $runId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $runId = Cache::get(self::ACTIVE_RUN_KEY) ?: Cache::get(self::LAST_RUN_KEY);

        if (! is_string($runId) || $runId === '') {
            return [
                'run_id' => null,
                'is_running' => false,
                'message' => 'No demo traffic run yet.',
            ];
        }

        return $this->getRun($runId) ?? [
            'run_id' => $runId,
            'is_running' => false,
            'message' => 'Demo traffic status expired.',
        ];
    }

    /**
     * @return array{deleted: int, run_id: string|null}
     */
    public function clear(): array
    {
        $runId = Cache::get(self::ACTIVE_RUN_KEY) ?: Cache::get(self::LAST_RUN_KEY);

        if (is_string($runId) && $runId !== '') {
            Cache::put($this->cancelKey($runId), true, now()->addMinutes(10));
        }

        $deleted = Notification::query()
            ->where('idempotency_key', 'like', self::IDP_PREFIX.'%')
            ->delete();

        $this->eventLog->clear();
        $this->metrics->resetOperationalCounters();

        if (is_string($runId) && $runId !== '') {
            $status = $this->getRun($runId) ?? ['run_id' => $runId, 'is_running' => false];
            $status['is_running'] = false;
            $status['deleted'] = $deleted;
            $status['message'] = sprintf('Cleared %d demo notifications', $deleted);
            $status['finished_at'] = now()->toIso8601String();
            $this->putRun($runId, $status);
            Cache::forget($this->cancelKey($runId));
        }

        Cache::forget(self::ACTIVE_RUN_KEY);

        return [
            'deleted' => $deleted,
            'run_id' => is_string($runId) ? $runId : null,
        ];
    }

    public function hasActiveRun(): bool
    {
        $runId = Cache::get(self::ACTIVE_RUN_KEY);

        if (! is_string($runId) || $runId === '') {
            return false;
        }

        $status = $this->getRun($runId);

        return (bool) ($status['is_running'] ?? false);
    }

    private function makeNotification(string $runId, int $tick, int $offset): Notification
    {
        $index = ($tick * 100) + $offset;
        $channel = Notification::channels()[$index % 3];
        $priority = Notification::priorities()[($tick + $offset) % 3];
        $profile = random_int(1, 100);
        $futureTime = Carbon::now()->addSeconds(random_int(45, 300));

        $baseAttributes = [
            'batch_id' => (string) Str::uuid(),
            'idempotency_key' => sprintf('%s%s-%d-%d', self::IDP_PREFIX, $runId, $tick, $offset),
            'channel' => $channel,
            'recipient' => $this->recipientFor($channel, $runId, $index),
            'content' => $this->contentFor($channel, $index),
            'priority' => $priority,
            'attempt_count' => 0,
        ];

        if ($profile <= 55) {
            return Notification::create($baseAttributes + [
                'status' => Notification::STATUS_QUEUED,
            ]);
        }

        if ($profile <= 75) {
            return Notification::create($baseAttributes + [
                'status' => Notification::STATUS_PENDING,
                'scheduled_at' => $futureTime,
            ]);
        }

        if ($profile <= 85) {
            return Notification::create($baseAttributes + [
                'status' => Notification::STATUS_CANCELLED,
                'scheduled_at' => $futureTime,
                'last_error' => 'Cancelled during demo seeding to simulate operator intervention.',
            ]);
        }

        if ($profile <= 93) {
            return Notification::create($baseAttributes + [
                'status' => Notification::STATUS_FAILED,
                'attempt_count' => random_int(1, 3),
                'last_error' => 'Simulated provider timeout during demo seed.',
            ]);
        }

        return Notification::create($baseAttributes + [
            'status' => Notification::STATUS_SENT,
            'attempt_count' => random_int(1, 2),
            'provider_response' => [
                'messageId' => sprintf('demo-msg-%s-%d', $runId, $index),
                'status' => 'accepted',
                'timestamp' => now()->toIso8601String(),
                'seeded' => true,
            ],
        ]);
    }

    private function dispatchForProcessing(Notification $notification): void
    {
        $dispatch = ProcessNotificationJob::dispatch($notification->id)->onQueue($notification->priority);

        if ($notification->scheduled_at?->isFuture()) {
            $dispatch->delay($notification->scheduled_at);
        }
    }

    private function recipientFor(string $channel, string $runId, int $index): string
    {
        return match ($channel) {
            Notification::CHANNEL_SMS => '+90555'.str_pad((string) (($index % 9000000) + 1000000), 7, '0', STR_PAD_LEFT),
            Notification::CHANNEL_EMAIL => sprintf('demo+%s-%d@example.test', substr($runId, 0, 8), $index),
            default => sprintf('demo-device-%s-%d', substr($runId, 0, 8), $index),
        };
    }

    private function contentFor(string $channel, int $index): string
    {
        return match ($channel) {
            Notification::CHANNEL_SMS => sprintf('Order #%d approved. Courier ETA %d min.', 10000 + $index, 5 + ($index % 25)),
            Notification::CHANNEL_EMAIL => sprintf('Campaign digest #%d is ready. Open the dashboard for updated engagement data.', 20000 + $index),
            default => sprintf('Inventory alert #%d resolved. Tap to review routing details.', 30000 + $index),
        };
    }

    private function shouldCancel(string $runId): bool
    {
        return (bool) Cache::get($this->cancelKey($runId), false);
    }

    private function cancelKey(string $runId): string
    {
        return self::CANCEL_KEY_PREFIX.$runId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRun(string $runId): ?array
    {
        $status = Cache::get(self::RUN_KEY_PREFIX.$runId);

        return is_array($status) ? $status : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putRun(string $runId, array $payload): void
    {
        Cache::put(self::RUN_KEY_PREFIX.$runId, $payload, now()->addMinutes(self::STATUS_TTL_MINUTES));
    }
}
