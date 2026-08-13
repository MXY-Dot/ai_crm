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

    'dify' => [
        'url' => env('DIFY_API_URL'),
        'api_key' => env('DIFY_API_KEY'),
        'timeout' => env('DIFY_TIMEOUT', 12),
    ],

    'chatwoot' => [
        'url' => env('CHATWOOT_URL'),
        'account_id' => env('CHATWOOT_ACCOUNT_ID'),
        'api_token' => env('CHATWOOT_API_TOKEN'),
        'webhook_secret' => env('CHATWOOT_WEBHOOK_SECRET'),
    ],

    'groq' => [
        'api_url' => env('GROQ_API_URL', 'https://api.groq.com/openai/v1'),
        'api_key' => env('GROQ_API_KEY'),
        'timeout' => env('GROQ_TIMEOUT', 20),
    ],

    // Platform-managed LLM provider keys (App\Support\Integrations\PlatformSettings)
    // — optional .env bootstrap; Super Admin can also set/override these at runtime.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

];
