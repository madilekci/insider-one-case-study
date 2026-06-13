<?php

namespace App\Http\Requests;

use App\Models\Notification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    private const CONTENT_LIMITS = [
        Notification::CHANNEL_SMS => 160,
        Notification::CHANNEL_PUSH => 240,
        Notification::CHANNEL_EMAIL => 5000,
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notifications' => ['sometimes', 'array', 'min:1', 'max:1000'],

            'channel' => ['required_without:notifications', Rule::in(Notification::channels())],
            'recipient' => ['required_without:notifications', 'string', 'max:255'],
            'content' => ['required_without:notifications', 'string'],
            'priority' => ['nullable', Rule::in(Notification::priorities())],

            'notifications.*.channel' => ['required_with:notifications', Rule::in(Notification::channels())],
            'notifications.*.recipient' => ['required_with:notifications', 'string', 'max:255'],
            'notifications.*.content' => ['required_with:notifications', 'string'],
            'notifications.*.priority' => ['nullable', Rule::in(Notification::priorities())],
            'notifications.*.idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $isBatch = $this->has('notifications');

            if ($isBatch && ($this->filled('channel') || $this->filled('recipient') || $this->filled('content'))) {
                $validator->errors()->add('notifications', 'Provide either a single notification payload or notifications array, not both.');

                return;
            }

            if (! $isBatch) {
                $channel = (string) $this->input('channel');
                $content = (string) $this->input('content');

                $this->validateContentLimit($validator, $channel, $content, 'content');

                return;
            }

            foreach ((array) $this->input('notifications', []) as $index => $item) {
                $channel = (string) ($item['channel'] ?? '');
                $content = (string) ($item['content'] ?? '');
                $this->validateContentLimit($validator, $channel, $content, "notifications.$index.content");
            }
        });
    }

    private function validateContentLimit($validator, string $channel, string $content, string $field): void
    {
        $limit = self::CONTENT_LIMITS[$channel] ?? null;

        if ($limit !== null && mb_strlen($content) > $limit) {
            $validator->errors()->add($field, "Content exceeds maximum length of {$limit} for {$channel} channel.");
        }
    }
}
