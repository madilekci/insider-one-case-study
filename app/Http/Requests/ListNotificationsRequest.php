<?php

namespace App\Http\Requests;

use App\Models\Notification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('per_page')) {
            $this->merge(['per_page' => 20]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(Notification::statuses())],
            'channel' => ['nullable', Rule::in(Notification::channels())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
