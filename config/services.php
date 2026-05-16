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
        'base_url'       => env('SQUAD_BASE_URL'),
        'webhook_secret' => env('SQUAD_WEBHOOK_SECRET', env('SQUAD_API_KEY')),
        'sms_sender_id'  => env('SQUAD_SMS_SENDER_ID', 'S-Alert'),
        'sms_on_submit'  => env('SQUAD_SMS_ON_SUBMIT', true),
    ],

    'ai_verification' => [
        'base_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token'    => env('AI_SERVICE_TOKEN'),
        'timeout'  => env('AI_SERVICE_TIMEOUT', 30),

        // Enrolment quality gate. A weak enrolled face template drags down
        // every future verification (genuine matches land borderline /
        // INCONCLUSIVE), so we reject poor enrolment captures up front.
        // The AI service deliberately does NOT gate enrolment quality
        // (see app/main.py: spoof_prob has known bias) — it's our call.
        'enrol_min_face_confidence' => (float) env('ENROL_MIN_FACE_CONFIDENCE', 0.80),
        // spoof_prob is the AI authors' flagged-as-biased signal — gate it
        // softly (high threshold) so it warns on egregious cases without
        // blocking legitimate enrolments the way a strict 0.3 would.
        'enrol_max_spoof_prob'      => (float) env('ENROL_MAX_SPOOF_PROB', 0.85),
    ],

    'vapi' => [
        'api_key'             => env('VAPI_API_KEY'),
        'base_url'            => env('VAPI_BASE_URL', 'https://api.vapi.ai'),
        'webhook_secret'      => env('VAPI_WEBHOOK_SECRET'),
        'phone_number_id'     => env('VAPI_PHONE_NUMBER_ID'),
        'assistant_verify_id' => env('VAPI_ASSISTANT_VERIFY_ID'),
        // Separate assistant for phone voice ENROLMENT (enrollment-worded
        // script). Falls back to the verify assistant if unset so the
        // enrol-by-phone flow still works without this configured.
        'assistant_enrol_id'  => env('VAPI_ASSISTANT_ENROL_ID', env('VAPI_ASSISTANT_VERIFY_ID')),
    ],

];
