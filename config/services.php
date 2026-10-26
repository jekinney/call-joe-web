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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // AI providers (App\Enums\LlmProvider, App\Services\LlmProviders). Which model does which job is chosen in
    // /admin/models; each model belongs to one of these providers, whose key is set here.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY', ''),
        'base_url' => env('ANTHROPIC_BASE_URL', ''),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 30),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY', ''),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
    ],

    'xai' => [
        'key' => env('XAI_API_KEY', ''),
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
        'timeout' => (int) env('XAI_TIMEOUT', 30),
        // Pictures contacts send (App\Support\Images\XaiImageClient): Grok Imagine, $0.02 a picture.
        'image_model' => env('XAI_IMAGE_MODEL', 'grok-imagine-image'),
        'image_timeout' => (int) env('XAI_IMAGE_TIMEOUT', 90),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY', ''),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'timeout' => (int) env('GROQ_TIMEOUT', 30),
    ],

    // Voice calls (App\Support\Voice\PipecatClient): our own voice service in voice/, speaking with Gemini Live.
    // `url` is where Laravel reaches it; `public_url` is where the browser connects (they differ when Laravel runs in
    // Docker and the service runs on the host). The secret is shared both ways (sessions in, reports out).
    'voice' => [
        'url' => env('VOICE_URL', 'http://host.docker.internal:8787'),
        'public_url' => env('VOICE_PUBLIC_URL', 'http://localhost:8787'),
        'secret' => env('VOICE_SECRET', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
