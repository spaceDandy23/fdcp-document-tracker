<?php

$fdcpAccountsBaseUrl = rtrim((string) env('FDCP_ACCOUNTS_BASE_URL', 'https://sso.fdcp.ph'), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'fdcp_accounts' => [
        'client_id' => env('FDCP_ACCOUNTS_CLIENT_ID'),
        'client_secret' => env('FDCP_ACCOUNTS_CLIENT_SECRET'),
        'redirect' => env('FDCP_ACCOUNTS_REDIRECT_URI'),
        'authorization_url' => $fdcpAccountsBaseUrl.'/oauth/authorize',
        'token_url' => $fdcpAccountsBaseUrl.'/oauth/token',
        'userinfo_url' => $fdcpAccountsBaseUrl.'/api/userinfo',
        'logout_url' => $fdcpAccountsBaseUrl.'/logout/sso',
        'guzzle' => [
            'connect_timeout' => 5,
            'timeout' => 10,
        ],

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
