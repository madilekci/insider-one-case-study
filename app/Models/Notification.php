<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_PUSH = 'push';

    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW = 'low';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'batch_id',
        'idempotency_key',
        'channel',
        'recipient',
        'content',
        'priority',
        'status',
        'scheduled_at',
        'provider_response',
        'attempt_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'scheduled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Notification $notification): void {
            if (! $notification->id) {
                $notification->id = (string) Str::uuid();
            }

            if (! $notification->priority) {
                $notification->priority = self::PRIORITY_NORMAL;
            }

            if (! $notification->status) {
                $notification->status = self::STATUS_QUEUED;
            }
        });
    }

    public static function channels(): array
    {
        return [self::CHANNEL_SMS, self::CHANNEL_EMAIL, self::CHANNEL_PUSH];
    }

    public static function priorities(): array
    {
        return [self::PRIORITY_HIGH, self::PRIORITY_NORMAL, self::PRIORITY_LOW];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_SENT,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }
}
