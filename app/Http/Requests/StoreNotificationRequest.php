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
        $templateKeys = array_keys(config('notification_templates', []));

        return [
            'notifications' => ['sometimes', 'array', 'min:1', 'max:1000'],

            'channel' => ['required_without:notifications', Rule::in(Notification::channels())],
            'recipient' => ['required_without:notifications', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'template_key' => ['nullable', 'string', 'max:255', Rule::in($templateKeys)],
            'template_variables' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'priority' => ['nullable', Rule::in(Notification::priorities())],

            'notifications.*.channel' => ['required_with:notifications', Rule::in(Notification::channels())],
            'notifications.*.recipient' => ['required_with:notifications', 'string', 'max:255'],
            'notifications.*.content' => ['nullable', 'string'],
            'notifications.*.template_key' => ['nullable', 'string', 'max:255', Rule::in($templateKeys)],
            'notifications.*.template_variables' => ['nullable', 'array'],
            'notifications.*.scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
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

                $this->validateContentOrTemplate($validator, [
                    'content' => $this->input('content'),
                    'template_key' => $this->input('template_key'),
                ], 'content', 'template_key');

                if ($this->filled('content')) {
                    $this->validateContentLimit($validator, $channel, $content, 'content');
                }

                return;
            }

            foreach ((array) $this->input('notifications', []) as $index => $item) {
                $channel = (string) ($item['channel'] ?? '');
                $content = (string) ($item['content'] ?? '');

                $this->validateContentOrTemplate(
                    $validator,
                    $item,
                    "notifications.$index.content",
                    "notifications.$index.template_key"
                );

                if (array_key_exists('content', $item) && $item['content'] !== null && $item['content'] !== '') {
                    $this->validateContentLimit($validator, $channel, $content, "notifications.$index.content");
                }
            }
        });
    }

    private function validateContentOrTemplate($validator, array $payload, string $contentField, string $templateField): void
    {
        $hasContent = isset($payload['content']) && $payload['content'] !== null && $payload['content'] !== '';
        $hasTemplate = isset($payload['template_key']) && $payload['template_key'] !== null && $payload['template_key'] !== '';

        if (! $hasContent && ! $hasTemplate) {
            $validator->errors()->add($contentField, 'Provide either content or template_key.');
        }

        if ($hasContent && $hasTemplate) {
            $validator->errors()->add($templateField, 'Provide either content or template_key, not both.');
        }
    }

    private function validateContentLimit($validator, string $channel, string $content, string $field): void
    {
        $limit = self::CONTENT_LIMITS[$channel] ?? null;

        if ($limit !== null && mb_strlen($content) > $limit) {
            $validator->errors()->add($field, "Content exceeds maximum length of {$limit} for {$channel} channel.");
        }
    }
}
