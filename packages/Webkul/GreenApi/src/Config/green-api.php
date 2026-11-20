<?php

return [
    'enabled' => (bool) env('GREEN_API_ENABLED', false),
    'api_url' => env('GREEN_API_URL', 'https://api.green-api.com'),
    'instance_id' => (string) env('GREEN_API_INSTANCE_ID', ''),
    'api_token' => env('GREEN_API_TOKEN', ''),
    'webhook_token' => env('GREEN_API_WEBHOOK_TOKEN', ''),
    'timeout' => 20,
    'queue' => env('GREEN_API_QUEUE', 'default'),
    'default_user_id' => env('GREEN_API_DEFAULT_USER_ID'),
];
