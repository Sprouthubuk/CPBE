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

    // Meta Conversions API. The token is only ever in the server's environment, never in the admin or the code.
    'meta' => [
        'capi_token' => env('META_CAPI_TOKEN'),
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        // SOW 3.3: daily ad spend for reports (Marketing API insights), when both are set.
        'ad_account_id' => env('META_AD_ACCOUNT_ID'),
        'marketing_token' => env('META_MARKETING_TOKEN'),
    ],

    // SOW 3.3: the email and SMS marketing platforms (App\Services\MarketingSync).
    'marketing' => [
        'email_driver' => env('MARKETING_EMAIL_DRIVER', 'none'),
        'email_key' => env('MARKETING_EMAIL_KEY'),
        'email_list' => env('MARKETING_EMAIL_LIST'),
        'webhook_url' => env('MARKETING_WEBHOOK_URL'),
        'webhook_secret' => env('MARKETING_WEBHOOK_SECRET'),
        'sms_driver' => env('SMS_MARKETING_DRIVER', 'none'),
        'sms_webhook_url' => env('SMS_MARKETING_WEBHOOK_URL'),
        'sms_optout_secret' => env('SMS_OPTOUT_SECRET'),
    ],

    'cashflows' => [
        'driver' => env('CASHFLOWS_DRIVER', 'cashflows'), // 'fake' only for tests and local development
        'base_url' => env('CASHFLOWS_BASE_URL', env('CASHFLOWS_ENV') === 'production' ? 'https://gateway.cashflows.com' : 'https://gateway-int.cashflows.com'),
        'configuration_id' => env('CASHFLOWS_CONFIGURATION_ID'),
        'api_key' => env('CASHFLOWS_API_KEY'),
    ],

];
