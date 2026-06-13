<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'content' => $this->content,
            'priority' => $this->priority,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'last_error' => $this->last_error,
            'provider_response' => $this->provider_response,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
