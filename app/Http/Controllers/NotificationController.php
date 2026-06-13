<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\NotificationTemplateService;
use App\Services\Observability\Metrics;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    private const CONTENT_LIMITS = [
        Notification::CHANNEL_SMS => 160,
        Notification::CHANNEL_PUSH => 240,
        Notification::CHANNEL_EMAIL => 5000,
    ];

    public function __construct(
        private Metrics $metrics,
        private NotificationTemplateService $templates,
    )
    {
    }

    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['notifications'])) {
            $batchId = (string) Str::uuid();
            $notifications = collect($data['notifications'])
                ->map(function (array $item) use ($batchId): Notification {
                    return $this->createOrReuseBatchNotification($item, $batchId);
                });

            $statusCode = $notifications->contains(fn (Notification $notification): bool => $notification->wasRecentlyCreated)
                ? 201
                : 200;

            return response()->json([
                'batch_id' => $batchId,
                'count' => $notifications->count(),
                'notifications' => NotificationResource::collection($notifications),
            ], $statusCode);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $existing = Notification::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return response()->json([
                    'notification' => new NotificationResource($existing),
                ]);
            }
        }

        $notification = Notification::create([
            'idempotency_key' => $idempotencyKey,
            'channel' => $data['channel'],
            'recipient' => $data['recipient'],
            'content' => $this->resolveContent($data),
            'template_key' => $data['template_key'] ?? null,
            'template_variables' => $data['template_variables'] ?? null,
            'priority' => $data['priority'] ?? Notification::PRIORITY_NORMAL,
            'status' => $this->resolveInitialStatus($data),
            'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
        ]);

        $this->metrics->incrementCreated($notification->channel);

        $this->dispatchForProcessing($notification);

        return response()->json([
            'notification' => new NotificationResource($notification),
        ], 201);
    }

    public function show(string $id): NotificationResource
    {
        $notification = Notification::query()->findOrFail($id);

        return new NotificationResource($notification);
    }

    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = Notification::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->string('channel')->toString());
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay());
        }

        $notifications = $query->paginate($request->integer('per_page', 20))->appends($request->query());

        return NotificationResource::collection($notifications);
    }

    public function batch(string $batchId): JsonResponse
    {
        $notifications = Notification::query()
            ->where('batch_id', $batchId)
            ->orderBy('created_at')
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([
                'message' => 'Batch not found.',
            ], 404);
        }

        return response()->json([
            'batch_id' => $batchId,
            'count' => $notifications->count(),
            'notifications' => NotificationResource::collection($notifications),
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $notification = Notification::query()->findOrFail($id);

        if (! in_array($notification->status, [Notification::STATUS_PENDING, Notification::STATUS_QUEUED], true)) {
            return response()->json([
                'message' => 'Only pending or queued notifications can be cancelled.',
            ], 422);
        }

        $notification->status = Notification::STATUS_CANCELLED;
        $notification->save();
        $this->metrics->incrementCancelled();

        return response()->json([
            'notification' => new NotificationResource($notification),
        ]);
    }

    private function createOrReuseBatchNotification(array $item, string $batchId): Notification
    {
        $idempotencyKey = $item['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = Notification::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        $notification = Notification::create([
            'batch_id' => $batchId,
            'idempotency_key' => $idempotencyKey,
            'channel' => $item['channel'],
            'recipient' => $item['recipient'],
            'content' => $this->resolveContent($item),
            'template_key' => $item['template_key'] ?? null,
            'template_variables' => $item['template_variables'] ?? null,
            'priority' => $item['priority'] ?? Notification::PRIORITY_NORMAL,
            'status' => $this->resolveInitialStatus($item),
            'scheduled_at' => isset($item['scheduled_at']) ? Carbon::parse($item['scheduled_at']) : null,
        ]);

        $this->metrics->incrementCreated($notification->channel);

        $this->dispatchForProcessing($notification);

        return $notification;
    }

    private function dispatchForProcessing(Notification $notification): void
    {
        $dispatch = ProcessNotificationJob::dispatch($notification->id)->onQueue($notification->priority);

        if ($notification->scheduled_at?->isFuture()) {
            $dispatch->delay($notification->scheduled_at);
        }
    }

    private function resolveContent(array $payload): string
    {
        if (! empty($payload['template_key'])) {
            $content = $this->templates->render(
                (string) $payload['template_key'],
                (string) $payload['channel'],
                (array) ($payload['template_variables'] ?? []),
            );

            $this->ensureContentLengthAllowed((string) $payload['channel'], $content);

            return $content;
        }

        $content = (string) ($payload['content'] ?? '');

        $this->ensureContentLengthAllowed((string) $payload['channel'], $content);

        return $content;
    }

    private function resolveInitialStatus(array $payload): string
    {
        if (! isset($payload['scheduled_at'])) {
            return Notification::STATUS_QUEUED;
        }

        return Carbon::parse($payload['scheduled_at'])->isFuture()
            ? Notification::STATUS_PENDING
            : Notification::STATUS_QUEUED;
    }

    private function ensureContentLengthAllowed(string $channel, string $content): void
    {
        $limit = self::CONTENT_LIMITS[$channel] ?? null;

        if ($limit !== null && mb_strlen($content) > $limit) {
            throw ValidationException::withMessages([
                'content' => ["Content exceeds maximum length of {$limit} for {$channel} channel."],
            ]);
        }
    }
}
