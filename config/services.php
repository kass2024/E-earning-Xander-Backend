<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zoom' => [
        // App #1 — Server-to-Server OAuth (REST: create/list/read meetings, recordings)
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'host_user_id' => env('ZOOM_HOST_USER_ID', 'me'),
        // Comma-separated licensed Zoom host emails on the same account (one per partner institution when auto-assigned).
        'host_pool' => env('ZOOM_HOST_POOL', ''),
        // Licensed hosts for main-platform admins, staff, and instructors (concurrent with institutions).
        'platform_host_pool' => env('ZOOM_PLATFORM_HOST_POOL', ''),
        // App #2 — General app / Embed (Meeting SDK JWT signatures only; never used for REST)
        'embed_client_id' => env('ZOOM_EMBED_CLIENT_ID', env('ZOOM_SDK_KEY', '')),
        'embed_client_secret' => env('ZOOM_EMBED_CLIENT_SECRET', env('ZOOM_SDK_SECRET', '')),
        'sdk_key' => env('ZOOM_EMBED_CLIENT_ID', env('ZOOM_SDK_KEY', '')),
        'sdk_secret' => env('ZOOM_EMBED_CLIENT_SECRET', env('ZOOM_SDK_SECRET', '')),
    ],

    'daily' => require __DIR__ . '/daily.php',

    'pathways_webinar' => [
        'timezone' => env('PATHWAYS_TIMEZONE', 'Africa/Kigali'),
        'zoom_join_url' => env('PATHWAYS_ZOOM_JOIN_URL', 'https://us06web.zoom.us/j/84024505834?pwd=S35BVbbF5OO8zY1zBMIw59YKw3L5Gx.1'),
        'zoom_meeting_id' => env('PATHWAYS_ZOOM_MEETING_ID'),
        'zoom_start_url' => env('PATHWAYS_ZOOM_START_URL'),
    ],

    'stripe' => [
        // Using keys already defined in .env
        'secret' => env('STRIPE_SECRET_KEY'),
        'key'    => env('STRIPE_PUBLIC_KEY'),
    ],

    /*
    | MoPay Gateway V1 — Xander must use its OWN MOPAY_* credentials and ONE receiver number.
    | Do not reuse F&R / frwanda AUTH_KEY, CALLBACK_SIGNING_KEY, CALLBACK_URL, or receiver MSISDN.
    */
    'mopay' => [
        'project_slug' => env('MOPAY_PROJECT_SLUG', 'xander'),
        'message_prefix' => env('MOPAY_MESSAGE_PREFIX', 'XANDER'),
        'account_id' => env('MOPAY_ACCOUNT_ID'),
        'auth_key' => env('MOPAY_AUTH_KEY'),
        'bearer_token' => env('MOPAY_BEARER_TOKEN'),
        'server_base_url' => rtrim(env('MOPAY_SERVER_BASE_URL', 'http://41.186.14.66:443/'), '/'),
        'token_url' => env('MOPAY_TOKEN_URL'),
        'category' => env('MOPAY_CATEGORY', 'BIZAO'),
        'callback_signing_key' => env('MOPAY_CALLBACK_SIGNING_KEY'),
        'callback_url' => env('MOPAY_CALLBACK_URL'),
        'default_country_code' => env('MOPAY_DEFAULT_COUNTRY_CODE', 'rw'),
        'default_mno' => env('MOPAY_DEFAULT_MNO', 'mtn'),
        'default_currency' => env('MOPAY_DEFAULT_CURRENCY', 'RWF'),
        'receiver_account_no' => env('MOPAY_RECEIVER_ACCOUNT_NO'),
        'payment_title' => env('MOPAY_PAYMENT_TITLE', 'Xander_course_payment'),
        'payment_details' => env('MOPAY_PAYMENT_DETAILS', 'Course enrollment payment'),
    ],

    'pcloud' => [
        'access_token' => env('PCLOUD_ACCESS_TOKEN'),
        'root_folder_id' => env('PCLOUD_ROOT_FOLDER_ID', 31887143130),
        'root_folder' => env('PCLOUD_ROOT_FOLDER', 'ParrotAcademy'),
        'base_url' => env('PCLOUD_API_URL', 'https://api.pcloud.com'),
        // Defaults to base_url. Do NOT use upload.pcloud.com — DNS often fails on cPanel.
        'upload_base_url' => env('PCLOUD_UPLOAD_URL'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    'gemini' => [
        'api_key' => env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', env('GOOGLE_AI_MODEL', 'gemini-2.5-flash')),
        'stt_model' => env('GEMINI_STT_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash')),
    ],

    'quiz_ai' => [
        'generation_provider' => env('QUIZ_AI_GENERATION_PROVIDER', 'gemini'),
        'generation_model' => env('QUIZ_AI_GENERATION_MODEL'),
        'claude_generation_model' => env('QUIZ_AI_CLAUDE_MODEL'),
        'fast_generation_model' => env('QUIZ_AI_FAST_MODEL'),
        'prefer_gemini_for_speed' => filter_var(env('QUIZ_AI_PREFER_GEMINI_SPEED', true), FILTER_VALIDATE_BOOL),
        'use_ai_knowledge_map' => filter_var(env('QUIZ_AI_USE_AI_KNOWLEDGE_MAP', false), FILTER_VALIDATE_BOOL),
        'enable_embeddings' => filter_var(env('QUIZ_AI_ENABLE_EMBEDDINGS', false), FILTER_VALIDATE_BOOL),
        'enable_media_transcription' => filter_var(env('QUIZ_AI_ENABLE_MEDIA_TRANSCRIPTION', false), FILTER_VALIDATE_BOOL),
        'embedding_model' => env('QUIZ_AI_EMBEDDING_MODEL', 'text-embedding-004'),
        'max_material_chars' => (int) env('QUIZ_AI_MAX_MATERIAL_CHARS', 18000),
        'material_cache_ttl' => (int) env('QUIZ_AI_MATERIAL_CACHE_TTL', 3600),
        'marking_primary' => env('QUIZ_AI_MARKING_PRIMARY', 'gemini'),
        'marking_secondary' => env('QUIZ_AI_MARKING_SECONDARY', 'gemini'),
        'gemini_only' => filter_var(env('QUIZ_AI_GEMINI_ONLY', true), FILTER_VALIDATE_BOOL),
        'generation_batch_size' => (int) env('QUIZ_AI_GENERATION_BATCH_SIZE', 10),
        'parallel_generation_batches' => filter_var(env('QUIZ_AI_PARALLEL_BATCHES', true), FILTER_VALIDATE_BOOL),
        'fast_context_chars_per_question' => (int) env('QUIZ_AI_FAST_CONTEXT_CHARS', 900),
    ],

];
