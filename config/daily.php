<?php

return [
    'enabled' => env('DAILY_INTEGRATION_ENABLED', false),
    'api_key' => env('DAILY_API_KEY'),
    'domain' => env('DAILY_DOMAIN'),
    'base_url' => env('DAILY_API_BASE_URL', 'https://api.daily.co/v1'),
    'webhook_hmac' => env('DAILY_WEBHOOK_HMAC', env('DAILY_WEBHOOK_SECRET')),
    'webhook_uuid' => env('DAILY_WEBHOOK_UUID'),
    'webhook_retry_type' => env('DAILY_WEBHOOK_RETRY_TYPE', 'exponential'),
    'webhook_base_url' => env('DAILY_WEBHOOK_BASE_URL', env('APP_URL')),
    'default_language' => env('DAILY_DEFAULT_LANGUAGE', 'en'),
    'room_grace_minutes' => (int) env('DAILY_ROOM_GRACE_MINUTES', 30),
    'token_grace_minutes' => (int) env('DAILY_TOKEN_GRACE_MINUTES', 15),
    'recording_enabled' => filter_var(env('DAILY_RECORDING_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Fallback only when platform_settings has no saved value. Admins switch via Live Meetings UI.
    'main_platform_meeting_provider' => env('MAIN_PLATFORM_MEETING_PROVIDER', 'daily'),
    // After leaving a Daily Prebuilt call, redirect here (see Daily domain config docs).
    'redirect_on_meeting_exit' => env('DAILY_REDIRECT_ON_MEETING_EXIT', env('FRONTEND_URL')),
    // Backward-compatible aliases used by existing code.
    'integration_enabled' => env('DAILY_INTEGRATION_ENABLED', false),
    'webhook_secret' => env('DAILY_WEBHOOK_HMAC', env('DAILY_WEBHOOK_SECRET')),
];
