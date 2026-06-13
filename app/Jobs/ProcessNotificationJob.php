<?php

namespace App\Jobs;

use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $notificationId)
    {
    }

    public function handle(): void
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

        $notification->status = Notification::STATUS_PROCESSING;
        $notification->save();

        try {
            // Provider integration is done in Step 4. For Step 3 we keep queue flow and status transitions explicit.
            $notification->status = Notification::STATUS_SENT;
            $notification->last_error = null;
            $notification->save();
        } catch (Throwable $e) {
            $notification->status = Notification::STATUS_FAILED;
            $notification->last_error = $e->getMessage();
            $notification->save();
        }
    }
}
