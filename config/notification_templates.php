<?php

return [
    'welcome_sms' => [
        'channel' => 'sms',
        'name' => 'Welcome SMS',
        'description' => 'Short onboarding message for SMS recipients.',
        'body' => 'Welcome {{name}}! Your verification code is {{code}}.',
        'variables' => ['name', 'code'],
    ],
    'weekly_summary_email' => [
        'channel' => 'email',
        'name' => 'Weekly Summary Email',
        'description' => 'Email summary with a personalized greeting.',
        'body' => 'Hi {{name}}, your weekly summary is ready.',
        'variables' => ['name'],
    ],
    'urgent_push_alert' => [
        'channel' => 'push',
        'name' => 'Urgent Push Alert',
        'description' => 'Short alert for time-sensitive push notifications.',
        'body' => '{{title}} - {{message}}',
        'variables' => ['title', 'message'],
    ],
];