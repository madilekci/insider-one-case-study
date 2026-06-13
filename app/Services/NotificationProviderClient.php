<?php

namespace App\Services;

use App\Models\Notification;
use App\Services\Exceptions\PermanentProviderException;
use App\Services\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class NotificationProviderClient
{
    /**
     * @return array<string, mixed>
     */
    public function send(Notification $notification): array
    {
        $url = (string) config('notifications.provider.webhook_url');

        if ($url === '') {
            throw new PermanentProviderException('Provider webhook URL is not configured.');
        }

        try {
            $response = Http::timeout((int) config('notifications.provider.timeout_seconds', 10))
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $e) {
            throw new TransientProviderException('Provider connection failed: '.$e->getMessage(), 0, $e);
        }

        return $this->parseResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(Response $response): array
    {
        if ($response->serverError() || $response->status() === 429) {
            throw new TransientProviderException('Provider returned transient status '.$response->status().'.');
        }

        if ($response->clientError()) {
            throw new PermanentProviderException('Provider returned client status '.$response->status().'.');
        }

        if (! $response->successful()) {
            throw new TransientProviderException('Provider returned unexpected status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new TransientProviderException('Provider response is not valid JSON object.');
        }

        return [
            'messageId' => $payload['messageId'] ?? null,
            'status' => $payload['status'] ?? 'accepted',
            'timestamp' => $payload['timestamp'] ?? now()->toISOString(),
            'http_status' => $response->status(),
        ];
    }
}
