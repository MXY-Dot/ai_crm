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
        // Outbound requests/minute WERO allows itself to send to this provider,
        // across every tenant combined (see LlmClient's rate limiter, ЭТАП 15.11)
        // — a self-imposed ceiling to avoid a multi-tenant burst tripping the
        // provider's own rate limit, not a claim about their real published limit.
        'rate_limit' => env('GROQ_RATE_LIMIT_PER_MINUTE', 60),
    ],

    // Platform-managed LLM provider keys (App\Support\Integrations\PlatformSettings)
    // — optional .env bootstrap; Super Admin can also set/override these at runtime.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'rate_limit' => env('OPENAI_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'rate_limit' => env('ANTHROPIC_RATE_LIMIT_PER_MINUTE', 50),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'rate_limit' => env('GEMINI_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'rate_limit' => env('DEEPSEEK_RATE_LIMIT_PER_MINUTE', 60),
    ],

    // Meta (WhatsApp Cloud API / Messenger / Instagram) webhook is registered
    // once per platform App in Meta's developer console, not per tenant like
    // Telegram — this app_secret/verify_token pair is shared across every
    // tenant; which tenant an event belongs to is resolved from the payload
    // itself (see App\Support\Integrations\MetaChannelResolver).
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
    ],

    // Fallback default only -- each tenant can override base_url in their own
    // integration settings (see AlifPayClient's docblock: the real Tajikistan
    // endpoint is unconfirmed, this points at Alif's documented Uzbekistan one).
    'alif' => [
        'base_url' => env('ALIF_PAY_BASE_URL', 'https://api.alifpay.uz/v2'),
    ],

    'telegram_moderator' => [
        'bot_token' => env('TELEGRAM_MODERATOR_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_MODERATOR_CHAT_ID'),
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
