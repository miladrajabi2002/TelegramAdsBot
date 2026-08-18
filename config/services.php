<?php

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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'init_data_ttl' => (int) env('TELEGRAM_INIT_DATA_TTL', 300),
    ],

    'zarinpay' => [
        'base_url' => env('ZARINPAY_BASE_URL', 'https://zarinmee.ir/api'),
        'access_token' => env('ZARINPAY_ACCESS_TOKEN'),
        'enabled' => (bool) env('ZARINPAY_ENABLED', false),
        'mock' => (bool) env('ZARINPAY_MOCK', false),
        'timeout' => (int) env('ZARINPAY_TIMEOUT', 15),
        'payment_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('ZARINPAY_PAYMENT_HOSTS', 'zarinmee.ir,mock.zarinpay.test')),
        ))),
    ],

    'nowpayments' => [
        'base_url' => env('NOWPAYMENTS_BASE_URL', 'https://api.nowpayments.io/v1'),
        'api_key' => env('NOWPAYMENTS_API_KEY'),
        'public_key' => env('NOWPAYMENTS_PUBLIC_KEY'),
        'ipn_secret' => env('NOWPAYMENTS_IPN_SECRET'),
        'enabled' => (bool) env('NOWPAYMENTS_ENABLED', false),
        'invoice_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('NOWPAYMENTS_INVOICE_HOSTS', 'nowpayments.io')),
        ))),
    ],

];
