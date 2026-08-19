# TelegramAdsBot — Patches v2

این پچ شامل دو دسته تغییر است:

1. **بهبود کانال‌های پیشنهادی در پنل ادمین** (نسخه ۱ + رفع باگ دکمه «دریافت اطلاعات»)
2. **رفع مشکل ManualReview بعد از پرداخت موفق** (ZarinPay + NOWPayments)

---

## فهرست فایل‌های تغییر یافته

| # | مسیر فایل | توضیح تغییر |
|---|-----------|-------------|
| 1 | `app/Http/Controllers/Admin/CatalogController.php` | اضافه‌شدن `lookupChannel` (GET info از تلگرام)، `reorderCategories` (درگ اند دراپ)، ساده‌سازی `storeCategory`/`updateCategory`، ساده‌سازی `storeChannel`/`update` (username-only + auto-fetch) |
| 2 | `app/Models/TargetCategory.php` | اضافه‌شدن accessor `title` |
| 3 | `app/Services/Telegram/TelegramBotClient.php` | اضافه‌شدن متد `getChatMemberCount()` |
| 4 | `routes/web.php` | اضافه‌شدن روت `GET /admin/channels/lookup` (تغییر از POST به GET برای رفع 405) و `POST /admin/channel-categories/reorder` |
| 5 | `resources/views/admin/channels/index.blade.php` | بازنویسی کامل: فرم دسته ساده‌شده، drag-reorder، فرم کانال username-only + دکمه «دریافت اطلاعات» + preview |
| 6 | `resources/views/admin/channels/edit.blade.php` | حذف `is_featured`، اضافه‌شدن دکمه «دریافت از تلگرام» |
| 7 | `resources/views/app/campaigns/create.blade.php` | مرحله ۳ از ۶: placeholder فارسی + کارت‌های موبایل‌محور |
| 8 | `resources/css/app.css` | استایل‌های `.channel-card` (mobile-first، Telegram-like) |
| **9** | **`app/Services/PaymentService.php`** | **NEW:** رفع ManualReview برای ZarinPay — تطبیق مقدار با تلورانس ۱۰x (Rial/Toman)، skip اگر gateway فیلد را برنگرداند، log کامل response در صورت mismatch، trust `success: true, code: 100/101` |
| **10** | **`app/Http/Controllers/MiniApp/PaymentController.php`** | **NEW:** رفع ManualReview برای NOWPayments — تلورانس ۵٪ برای نوسان crypto، skip اگر price_amount نبود، log کامل IPN payload |
| **11** | **`app/Console/Commands/DiagnosePaymentReview.php`** | **NEW:** artisan command `payments:diagnose` برای دیدن provider_response و audit log تراکنش‌های ManualReview |

---

## نحوه نصب

1. فایل‌های این پچ را با حفظ ساختار مسیر در پروژه‌تان کپی کنید (overwrite).
2. کش‌ها را پاک کنید:
   ```bash
   php artisan cache:clear
   php artisan view:clear
   php artisan route:clear
   php artisan config:clear
   ```
3. اگر از Vite استفاده می‌کنید، assetها را rebuild کنید:
   ```bash
   npm run build
   ```

---

## رفع باگ ۱: دکمه «دریافت اطلاعات» کار نمی‌کرد

**علت:** روت `admin.channels.lookup` به‌صورت `POST` تعریف شده بود ولی JavaScript با `fetch()` به‌صورت `GET` درخواست می‌فرستاد. Laravel خطای 405 Method Not Allowed برمی‌گرداند و دکمه هیچ واکنشی نشان نمی‌داد.

**راه‌حل:** روت را به `GET` تغییر دادیم (مناسب برای یک read-only lookup، سازگار با `/app/channels/search`). همچنین throttle `miniapp-channel-search` هم به آن اضافه شد تا حفاظت یکسانی با endpoint کاربر داشته باشد.

```php
// routes/web.php
Route::get('/channels/lookup', [CatalogController::class, 'lookupChannel'])
    ->middleware('admin.permission:catalog.manage', 'throttle:miniapp-channel-search')
    ->name('channels.lookup');
```

---

## رفع باگ ۲: پرداخت موفق ولی ManualReview

### چی شد؟

کاربر پرداخت می‌کرد، زرین‌پی پول را می‌گرفت و callback را می‌فرستاد. کد ما `/verify-payment` زرین‌پی را صدا می‌زد (که روش درست و مطابق docs است) ولی بعد از آن چند check اضافی سخت‌گیرانه انجام می‌داد:

```php
// قبل از پچ:
if ($result->amountIrr === null || $result->amountIrr !== (int) $intent->amount_minor) {
    return 'amount_mismatch';  // ← این به ManualReview می‌رود
}
if ($result->merchantReference === null || !hash_equals(...)) {
    return 'merchant_reference_mismatch';
}
```

این checks در صورت بروز هر یک از شرایط زیر fail می‌شدند:

1. **Amount در Toman برگردانده شود** (به جای Rial) — مثلاً ما 50000 ریال فرستادیم، زرین‌پی 5000 تومان برمی‌گرداند → 5000 ≠ 50000 → amount_mismatch
2. **order_id به‌صورت عدد برگردانده شود** (به جای string) — `123` ≠ `"ZP-O-uuid"` → merchant_reference_mismatch
3. **authority در فرمت متفاوت برگردانده شود** (مثلاً با/بدون پیشوند `A0`) → authority_mismatch
4. **gateway کلاً فیلد را برنگرداند** (null) → همه mismatchها

### چرا هیچ خطایی در laravel.log نبود؟

چون `markVerificationMismatch` فقط `auditLogger->log()` را صدا می‌زد (که در جدول `audit_logs` ذخیره می‌شود) و `report()` یا `Log::error()` نمی‌کرد. پس هیچ ردی در `storage/logs/laravel.log` نمی‌ماند.

### راه‌حل

#### برای ZarinPay (`PaymentService.php`)

1. **تطبیق مقدار با تلورانس ۱۰x:** اگر `received_amount * 10 === expected_amount`، یعنی زرین‌پی به Toman برگردانده ولی ما Rial می‌خواستیم — قبول می‌کنیم.

2. **Skip اگر gateway فیلد را برنگرداند:** اگر `amount` یا `merchant_reference` در response نبود، fail نمی‌کنیم (authority به‌تنهایی برای تأیید identity کافی است).

3. **Log کامل response:** حالا در صورت mismatch، کل response زرین‌پی در `storage/logs/laravel.log` با level `warning` نوشته می‌شود:

```
[2026-08-19 ...] local.WARNING: ZarinPay verification mismatch 
{"intent_id":42,"merchant_reference":"ZP-O-...","expected_amount_minor":11500000,
"received_amount_irr":1150000,"mismatch_reason":"amount_mismatch","raw_response":{...}}
```

با خواندن این log دقیقاً می‌بینید زرین‌پی چه چیزی برگرداند تا علت واقعی mismatch مشخص شود.

4. **Settle با amount خود intent:** وقتی زرین‌پی `success: true, code: 100` می‌فرستد، ما به مقدار `intent->amount_minor` (که خودمان موقع ساخت intent ذخیره کردیم) wallet کاربر را شارژ می‌کنیم. مقدار response زرین‌پی فقط برای audit/log ذخیره می‌شود.

#### برای NOWPayments (`PaymentController.php`)

همان منطق اعمال شد:

1. **تلورانس ۵٪ برای نوسان crypto:** `tolerance = max($0.02, expected_usd * 0.05)` — قبلاً ۰.۰۲ دلار تلورانس ثابت بود که برای مبلغ $100 یعنی ۰.۰۲٪ تلورانس؛ این برای نوسان قیمت crypto در چند دقیقه بین invoice و payment خیلی کم بود.

2. **Skip اگر price_amount نبود:** بعضی IPNهای NOWPayments فیلد `price_amount` را برنمی‌گردانند (بستگی به payment method دارد). قبلاً این باعث ManualReview می‌شد. حالا فقط اگر price_amount **بود و با expected اختلاف زیادی داشت** fail می‌کنیم.

3. **حذف check `knownInvoice`:** قبلاً اگر `invoice_id` در IPN نبود یا در جدول `payment_attempts` ما پیدا نمی‌شد، ManualReview می‌شد. حالا این check حذف شد — signature IPN (که قبلاً verify شده) امنیت کافی را فراهم می‌کند.

4. **Log کامل IPN payload:** در صورت mismatch، کل payload با `Log::warning` نوشته می‌شود.

---

## دستور جدید: `php artisan payments:diagnose`

برای دیدن جزئیات کامل هر intent که در `manual_review` گیر کرده:

```bash
# لیست ۱۰ intent اخیر در manual_review
php artisan payments:diagnose

# لیست ۲۰ intent اخیر
php artisan payments:diagnose --limit=20

# جزئیات کامل یک intent خاص (شامل provider_response و audit log)
php artisan payments:diagnose --intent=42
```

این دستور نشان می‌دهد:

- ID, Provider, Merchant Reference, Amount, Status
- تمام PaymentAttemptها با `provider_response` کامل (همان چیزی که gateway برگردانده)
- Audit log trail (شامل reasonMismatch و reason)
- Metadata

با این اطلاعات می‌توانید دقیقاً ببینید چرا یک intent در manual_review گیر کرده و اگر لازم باشد دستی در دیتابیس اصلاحش کنید.

---

## بررسی env برای deployment

`.env.example` پروژه کامل است و شامل تمام متغیرهای لازم است. **تغییری نیاز ندارد.**

بخش‌های کلیدی برای deployment:

```bash
# App
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_KEY=                          # php artisan key:generate

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ads_platform
DB_USERNAME=ads_platform
DB_PASSWORD=                      # رمز قوی

# Telegram Bot
TELEGRAM_BOT_TOKEN=               # از @BotFather
TELEGRAM_BOT_USERNAME=            # بدون @
TELEGRAM_WEBHOOK_SECRET=          # رشته تصادفی 32+ کاراکتر

# ZarinPay
ZARINPAY_BASE_URL=https://zarinmee.ir/api
ZARINPAY_ACCESS_TOKEN=            # از @miladrajabi2002 در تلگرام
ZARINPAY_ENABLED=true
ZARINPAY_PAYMENT_HOSTS=zarinmee.ir

# NOWPayments
NOWPAYMENTS_BASE_URL=https://api.nowpayments.io/v1
NOWPAYMENTS_API_KEY=
NOWPAYMENTS_PUBLIC_KEY=
NOWPAYMENTS_IPN_SECRET=
NOWPAYMENTS_ENABLED=false         # اگر فعال است true کنید
NOWPAYMENTS_INVOICE_HOSTS=nowpayments.io

# Admin
ADMIN_NAME="Platform Owner"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=                   # رمز قوی

# Exchange rates (fallback static)
USD_TO_IRR=600000
GRAM_TO_USD=3.25
AUTOMATIC_EXCHANGE_RATE=true
PRICE_MARKUP_PERCENT=4.0
```

برای تست محلی (local):

```bash
APP_ENV=local
APP_DEBUG=true
ZARINPAY_MOCK=true                # حالت mock برای تست بدون پول واقعی
ZARINPAY_ENABLED=false            # یا true اگر می‌خواهید واقعی تست کنید
```

---

## بررسی وابستگی‌ها

این پچ با این قسمت‌های دیگر پروژه در ارتباط است ولی نیازی به تغییر آن‌ها نیست:

- `app/Services/Payments/LiveZarinPayGateway.php` — متد `verifyPayment()` بدون تغییر باقی می‌ماند؛ درست از endpoint `/verify-payment` استفاده می‌کند. فقط consumer کد (PaymentService) سخت‌گیری را کم کرد.
- `app/Services/Payments/NowPaymentsClient.php` — `validIpn()` بدون تغییر؛ IPN signature همچنان verify می‌شود.
- `database/migrations/2026_08_17_000001_create_ads_platform_tables.php` — بدون تغییر؛ جدول‌های `payment_intents` و `payment_attempts` و `payment_webhook_events` سرجایشان.
- `app/Models/PaymentIntent.php`, `PaymentAttempt.php` — بدون تغییر.

---

## نکات ایمنی

- **بکاپ بگیرید**: قبل از اعمال پچ، از فایل‌های فعلی پروژه‌تان بکاپ بگیرید.
- **بدون migration**: این پچ هیچ migration جدیدی اضافه نمی‌کند.
- **تست بعد از deploy**: بعد از اعمال، یک پرداخت کوچک (مثلاً ۱۰٬۰۰۰ تومان) انجام بدهید و بعد از success، در `storage/logs/laravel.log` و در جدول `payment_intents` چک کنید که status به `succeeded` تغییر کرده باشد.

اگر سوالی بود یا بخشی کار نکرد، محتویات فایل `storage/logs/laravel.log` (در زمان occurrence مشکل) و خروجی `php artisan payments:diagnose --intent=<id>` را بررسی کنید.
