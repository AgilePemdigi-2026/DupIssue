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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github_app' => [
        'app_id' => env('GITHUB_APP_ID'),
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'slug' => env('GITHUB_APP_SLUG'),
        'callback_url' => env('GITHUB_APP_CALLBACK_URL'),
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    ],

    'nlp' => [
        'base_url' => env('NLP_SERVICE_URL'),
        'timeout' => 10,
        'connect_timeout' => 5,
    ],

];
