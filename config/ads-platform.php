<?php

return [
    'brand' => env('ADS_PLATFORM_BRAND', 'Ads Platform'),
    'support_username' => env('ADS_PLATFORM_SUPPORT_USERNAME'),
    'channel_username' => env('ADS_PLATFORM_CHANNEL_USERNAME'),
    'service_markup_bps' => (int) env('ADS_PLATFORM_MARKUP_BPS', 1500),
    'minimum_order_irr' => (int) env('ADS_PLATFORM_MINIMUM_ORDER_IRR', 1_000_000),
    'minimum_target_members' => (int) env('ADS_PLATFORM_MIN_TARGET_MEMBERS', 1000),
    'max_channels_per_category' => (int) env('ADS_PLATFORM_MAX_CHANNELS_PER_CATEGORY', 30),
    'display_timezone' => env('ADS_PLATFORM_TIMEZONE', 'Asia/Tehran'),
    'demo_mode' => (bool) env('ADS_PLATFORM_DEMO_MODE', false),
    'demo_telegram_user_id' => (int) env('ADS_PLATFORM_DEMO_TELEGRAM_USER_ID', 900000001),
    'kyc_retention_days' => (int) env('KYC_RETENTION_DAYS', 1825),
    'kyc_hmac_key' => env('KYC_HMAC_KEY') ?: env('APP_KEY'),
    'usd_to_irr' => (int) env('USD_TO_IRR', 600000),
    'gram_to_usd' => (float) env('GRAM_TO_USD', 3.25),
];
