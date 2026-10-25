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

    // AI models (App\Support\Llm\GroqClient). Models themselves are chosen in /admin/models.
    'groq' => [
        'key' => env('GROQ_API_KEY', ''),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'timeout' => (int) env('GROQ_TIMEOUT', 30),
    ],

    // Voice calls (App\Support\Voice\VapiClient). The webhook URL must be reachable from the internet (in development,
    // through a tunnel such as ngrok); the secret is sent by Vapi with every report.
    'vapi' => [
        'private_key' => env('VAPI_PRIVATE_KEY', ''),
        'public_key' => env('VAPI_PUBLIC_KEY', ''),
        'base_url' => env('VAPI_BASE_URL', 'https://api.vapi.ai'),
        'webhook_url' => env('VAPI_WEBHOOK_URL') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/webhooks/vapi',
        'webhook_secret' => env('VAPI_WEBHOOK_SECRET', ''),
    ],

    // Pictures contacts send (App\Support\Images\TogetherImageClient). FLUX.1 schnell: a few steps, a few seconds.
    'together' => [
        'key' => env('TOGETHER_API_KEY', ''),
        'base_url' => env('TOGETHER_BASE_URL', 'https://api.together.xyz/v1'),
        'model' => env('TOGETHER_IMAGE_MODEL', 'black-forest-labs/FLUX.1-schnell'),
        'steps' => (int) env('TOGETHER_IMAGE_STEPS', 4),
        'timeout' => (int) env('TOGETHER_TIMEOUT', 60),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
