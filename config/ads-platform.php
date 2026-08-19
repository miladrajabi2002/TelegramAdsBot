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

    // ─── Live price feed (USD/IRR + GRAM/USD) ─────────────────────────
    // The PriceFeedService tries each source in order; if all fail, the
    // static defaults above are used. The markup_percent is applied to
    // every fetched rate so the platform quotes a "buy" rate (market +
    // premium), which is what the customer will actually pay.
    'price_feed_ttl_seconds' => (int) env('PRICE_FEED_TTL_SECONDS', 300),
    'price_markup_percent' => (float) env('PRICE_MARKUP_PERCENT', 4.0),
    'tgju_url' => env('TGJU_URL', 'https://call4.tgju.org/ajax.json'),
    'bonbast_url' => env('BONBAST_URL', 'https://bonbast.com/api/rates'),
    'navasan_url' => env('NAVASAN_URL', 'https://navasan.net/api/v1/api.php'),
    'navasan_api_key' => env('NAVASAN_API_KEY', ''),
    'automatic_exchange_rate' => (bool) env('AUTOMATIC_EXCHANGE_RATE', true),

    // ─── KYC fast-review SLA banner ───────────────────────────────────
    // The "fast verification ~1h, max 24h" banner reads these to display
    // the SLA in the user's locale on the wallet and home pages.
    'kyc_sla_fast_minutes' => (int) env('KYC_SLA_FAST_MINUTES', 60),
    'kyc_sla_max_hours' => (int) env('KYC_SLA_MAX_HOURS', 24),

    // ─── Profile photo refresh ────────────────────────────────────────
    // When authenticating, the SessionController will call Telegram's
    // getUserProfilePhotos once per user per TTL window to refresh the
    // avatar shown in the Mini App topbar + account page. The TTL keeps
    // us from hitting the Bot API on every page load.
    'profile_photo_ttl_seconds' => (int) env('PROFILE_PHOTO_TTL_SECONDS', 6 * 3600),

    // ─── Channel search ───────────────────────────────────────────────
    // When the user enters a username or chat-id in the campaign-creation
    // channel picker, we call Telegram's getChat to resolve the metadata
    // (title, avatar, etc.). Per-user throttle to prevent abuse.
    'channel_search_per_minute' => (int) env('CHANNEL_SEARCH_PER_MINUTE', 30),

    // ─── ZarinPal payment amount tolerance ───────────────────────────
    // ZarinPal-compatible aggregators (zarinmee.ir, zarinpal.com, etc.)
    // sometimes return a different amount in the verify-payment response
    // than what we sent in the create-payment request:
    //
    //   • Some return the amount in Toman (10x smaller) — handled
    //     separately by the *10 ratio check.
    //   • Some add a service fee on top of the original amount (e.g. a
    //     2–6% fee charged by the aggregator). This fee is the gateway's
    //     revenue, not the user's wallet balance — we still credit the
    //     user the original `intent.amount_minor`.
    //
    // This config sets how much of a delta we tolerate before flagging the
    // verification as a mismatch (which puts the intent in `manual_review`).
    // Default ±10% is wide enough to absorb any normal fee without letting
    // a totally wrong amount through. Set to 0 to disable the tolerance
    // and require an exact match.
    'zarinpay_fee_tolerance_percent' => (float) env('ZARINPAY_FEE_TOLERANCE_PERCENT', 10.0),

    // ─── App splash loader ─────────────────────────────────────────────
    // When true, Mini App pages show a brief branded splash on first paint
    // that fades out after ~1.6s. Disable for instant-load feel.
    'show_splash' => (bool) env('APP_SHOW_SPLASH', true),
    'splash_min_duration_ms' => (int) env('APP_SPLASH_MIN_MS', 600),
];
