<?php

return [
    'brand' => env('ADS_PLATFORM_BRAND', 'Ads Platform'),
    'support_username' => env('ADS_PLATFORM_SUPPORT_USERNAME'),
    'channel_username' => env('ADS_PLATFORM_CHANNEL_USERNAME'),

    // Hidden admin URL prefix. Route names stay admin.* so internal links and
    // permissions keep working. Example: /jsfiopios5/admin/login
    'admin_path_prefix' => trim((string) env('ADMIN_PATH_PREFIX', 'jsfiopios5/admin'), '/') ?: 'jsfiopios5/admin',

    'service_markup_bps' => (int) env('ADS_PLATFORM_MARKUP_BPS', 1500),
    'minimum_order_irr' => (int) env('ADS_PLATFORM_MINIMUM_ORDER_IRR', 1_000_000),
    'minimum_target_members' => (int) env('ADS_PLATFORM_MIN_TARGET_MEMBERS', 1000),
    'max_channels_per_category' => (int) env('ADS_PLATFORM_MAX_CHANNELS_PER_CATEGORY', 30),
    'display_timezone' => env('ADS_PLATFORM_TIMEZONE', 'Asia/Tehran'),
    'demo_mode' => (bool) env('ADS_PLATFORM_DEMO_MODE', false),
    'demo_telegram_user_id' => (int) env('ADS_PLATFORM_DEMO_TELEGRAM_USER_ID', 900000001),
    'kyc_retention_days' => (int) env('KYC_RETENTION_DAYS', 1825),
    'kyc_hmac_key' => env('KYC_HMAC_KEY') ?: env('APP_KEY'),

    // Emergency fallback values only. Normal pricing always uses Exir.
    'usd_to_irr' => (int) env('USD_TO_IRR', 600000),
    'gram_to_usd' => (float) env('GRAM_TO_USD', 3.25),

    // Live Exir feed. The service uses a fixed 60-second current cache and a
    // persistent last-known-good value for each market independently.
    'price_feed_ttl_seconds' => 60,
    'price_markup_usd_percent' => (float) env('PRICE_MARKUP_USD_PERCENT', 5.0),
    'price_markup_ton_percent' => (float) env('PRICE_MARKUP_TON_PERCENT', 2.0),
    'price_markup_percent' => (float) env('PRICE_MARKUP_PERCENT', 5.0), // legacy alias
    'exir_usdt_irt_url' => env('EXIR_USDT_IRT_URL', 'https://api.exir.io/v2/ticker?symbol=usdt-irt'),
    'exir_ton_usdt_url' => env('EXIR_TON_USDT_URL', 'https://api.exir.io/v2/ticker?symbol=ton-usdt'),

    'kyc_sla_fast_minutes' => (int) env('KYC_SLA_FAST_MINUTES', 60),
    'kyc_sla_max_hours' => (int) env('KYC_SLA_MAX_HOURS', 24),

    'profile_photo_ttl_seconds' => (int) env('PROFILE_PHOTO_TTL_SECONDS', 6 * 3600),
    'channel_search_per_minute' => (int) env('CHANNEL_SEARCH_PER_MINUTE', 30),

    // ZarinPay-compatible gateways may report a positive surcharge on verify.
    // This is a tolerance for provider-added fees, not an editable user price.
    'zarinpay_fee_tolerance_percent' => (float) env('ZARINPAY_FEE_TOLERANCE_PERCENT', 10.0),

    'show_splash' => (bool) env('APP_SHOW_SPLASH', true),
    'splash_min_duration_ms' => (int) env('APP_SPLASH_MIN_MS', 600),
];
