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

    'squad' => [
        'api_key'        => env('SQUAD_API_KEY'),
        'base_url'       => env('SQUAD_BASE_URL', 'https://api-d.squadco.com'),
        'webhook_secret' => env('SQUAD_WEBHOOK_SECRET'),
    ],

    'ai_verification' => [
        'base_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token'    => env('AI_SERVICE_TOKEN'),
        'timeout'  => env('AI_SERVICE_TIMEOUT', 30),
    ],

    'vapi' => [
        'api_key'             => env('VAPI_API_KEY'),
        'base_url'            => env('VAPI_BASE_URL', 'https://api.vapi.ai'),
        'webhook_secret'      => env('VAPI_WEBHOOK_SECRET'),
        'phone_number_id'     => env('VAPI_PHONE_NUMBER_ID'),
        'assistant_verify_id' => env('VAPI_ASSISTANT_VERIFY_ID'),
    ],

];
