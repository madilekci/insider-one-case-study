<?php

return [
    'provider' => [
        'webhook_url' => env('NOTIFICATION_PROVIDER_WEBHOOK_URL'),
        'timeout_seconds' => (int) env('NOTIFICATION_PROVIDER_TIMEOUT_SECONDS', 10),
    ],
    'rate_limit' => [
        'per_second' => (int) env('NOTIFICATION_RATE_LIMIT_PER_SECOND', 100),
        'release_seconds' => (int) env('NOTIFICATION_RATE_LIMIT_RELEASE_SECONDS', 1),
    ],
];
