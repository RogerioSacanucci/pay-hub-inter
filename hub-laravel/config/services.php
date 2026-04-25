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

    'waymb' => [
        'url' => env('WAYMB_URL'),
        'account_email' => env('WAYMB_ACCOUNT_EMAIL'),
    ],

    'tiktok' => [
        'app_id' => env('TIKTOK_APP_ID'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        'oauth_redirect' => env('TIKTOK_OAUTH_REDIRECT'),
        'oauth_authorize_url' => env('TIKTOK_OAUTH_AUTHORIZE_URL', 'https://business-api.tiktok.com/portal/auth'),
        'open_api_base' => env('TIKTOK_OPEN_API_BASE', 'https://business-api.tiktok.com/open_api/v1.3'),
        'dashboard_url' => env('TIKTOK_DASHBOARD_URL', env('FRONTEND_URL')),
    ],

];
