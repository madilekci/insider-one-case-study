<?php

return [
    'provider' => [
        'webhook_url' => env('NOTIFICATION_PROVIDER_WEBHOOK_URL'),
        'timeout_seconds' => (int) env('NOTIFICATION_PROVIDER_TIMEOUT_SECONDS', 10),
    ],
];
