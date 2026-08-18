# لیست تغییرات — TelegramAdsBot

این فهرست تمام فایل‌هایی است که در این پچ تغییر کرده یا اضافه شده‌اند. مسیرها نسبی به ریشه پروژه هستند و در فایل ZIP با همین ساختار قرار گرفته‌اند تا بتوانید به‌صورت دستی روی نسخه محلی خود جایگزین کنید.

> **روش جایگزینی:** محتوای ZIP را در ریشه پروژه‌ی خود (کنار `composer.json`) extract کنید. همه فایل‌ها با ساختار اصلی overwrite می‌شوند. سپس `sudo bash bin/update.sh` را اجرا کنید تا تغییرات اعمال شود (composer + npm + migrate + cache + restart pm2/nginx/php-fpm).

---

## ۱) ریشه‌دار (Root-level)

| مسیر فایل | نوع تغییر | توضیح |
|---|---|---|
| `app/Http/Controllers/TelegramWebhookController.php` | اصلاح اساسی | پشتیبانی از `callback_query` (دکمه‌های inline)، انتخاب زبان فقط یک بار (با رصد `locale_set_at`)، منوی اصلی با inline buttons به جای keyboard. **جدید:** دکمه «ورود به مینی‌اپ» حالا شامل `?t=<magic_token>` است تا حتی اگر initData خالی باشد، کاربر با توکن وارد شود. توکن در هر /start ریست می‌شود. |
| `app/Http/Controllers/MiniApp/SessionController.php` | اصلاح اساسی | احراز هویت سه‌لایه: ۱) `init_data` (امن، با HMAC) ۲) `token` (magic_token از URL) ۳) `init_data_unsafe` (بدون امضا، آخرین راه‌حل). حتی اگر Telegram initData را تزریق نکند، کاربر وارد می‌شود. |
| `app/Http/Controllers/MiniApp/HomeController.php` | اصلاح | جایگزینی ۶ کوئری متوالی `SUM` با یک کوئری grouped واحد (`LedgerService::balancesFor`). اولین صفحه‌ای که هر کاربر می‌بیند، حالا یک‌باره باز می‌شود. |
| `app/Http/Controllers/MiniApp/SupportController.php` | اصلاح | جایگزینی `LIKE '%Admin'` با `Admin::class` (مستقیم morph class) + lazy-load کمپین‌ها فقط وقتی تیکت جدید ساخته می‌شود. |
| `app/Http/Controllers/Admin/SupportController.php` | اصلاح | همان morph fix + اضافه‌شدن فیلتر `priority` و `assigned_admin_id` (با «Mine only» shortcut) + escape کردن wildcards (`%`، `_`) در جست‌وجو. |
| `app/Models/User.php` | اصلاح اساسی | اضافه‌شدن `locale_set_at` و `magic_token` به fillable، متد کمکی `hasChosenLocale()`، و متد `rotateMagicToken()` که در هر /start فراخوانی می‌شود تا توکن قبلی باطل شود. `magic_token` به‌صورت خودکار در ایجاد کاربر جدید ساخته می‌شود (booted hook). |
| `database/migrations/2026_08_18_000001_add_locale_set_at_to_users_table.php` | جدید | مایگریشن افزودن ستون `locale_set_at` (timestamp nullable) به جدول `users`. |
| `database/migrations/2026_08_18_000002_add_magic_token_to_users_table.php` | جدید | مایگریشن افزودن ستون `magic_token` (string, unique, nullable) به جدول `users`. کاربران موجود به‌صورت خودکار با توکن backfill می‌شوند. |
| `app/Services/Telegram/TelegramBotClient.php` | اصلاح اساسی | رفع TypeError: متد `call()` حالا `mixed` برمی‌گرداند (نه فقط array) — `setWebhook` و `answerCallbackQuery` در Telegram `true` برمی‌گردانند، نه array. متد `setWebhook()` حالا `bool` برمی‌گرداند. |
| `app/Services/LedgerService.php` | اصلاح اساسی | اضافه‌شدن دو متد جدید: `balancesFor($owner)` و `balancesForMany($owners, $type)` — یک کوئری grouped واحد به‌جای N+1 در هر صفحه‌ای که موجودی کیف پول نمایش می‌دهد (Home، Wallet، Admin Dashboard، Admin Users list، Admin User show). |
| `app/Providers/AppServiceProvider.php` | اصلاح | cache کردن `pendingKycCount` با TTL 60 ثانیه در view composer — قبلاً در هر صفحه ادمین یک `COUNT(*)` روی `kyc_applications` اجرا می‌شد. |
| `app/Jobs/SendTelegramMessage.php` | بدون تغییر منطقی | فقط commentها کوتاه شد. |
| `resources/js/app.js` | اصلاح اساسی | ۱) `telegram.ready(callback)` به‌جای `ready()` همگام. ۲) دکمه «تلاش دوباره» در صفحه ورود. ۳) auto-scroll ticket threads به آخرین پیام. ۴) scrollspy برای تب‌های پنل ادمین. ۵) data-auto-submit. ۶) **احراز هویت سه‌لایه در فرانت‌اند:** اگر `initData` خالی بود، `?t=<token>` از URL و `initDataUnsafe.user` را هم به بک‌اند می‌فرستد. |
| `resources/views/app/entry.blade.php` | اصلاح اساسی | ۱) دکمه «تلاش دوباره». ۲) **دکمه «ورود از تلگرام»** که با `tg://resolve?domain=…&start=start` ربات را در تلگرام باز می‌کند و کاربر را به /start می‌برد. ۳) راهنمای بهتر. |
| `resources/views/components/icon.blade.php` | اصلاح | اضافه‌شدن آیکون جدید `refresh` برای دکمه Retry. |
| `resources/views/app/campaigns/index.blade.php` | اصلاح | ۱) حذف فیلتر `draft` (unreachable) و افزودن `queued_for_telegram`، `pause_requested`، `resume_requested`. ۲) تشخیص «هیچ سفارشی نیست» از «فیلتر چیزی پیدا نکرد». ۳) reset page=1 هنگام تغییر فیلتر. ۴) دکمه «پاک کردن فیلتر». |
| `resources/views/app/campaigns/show.blade.php` | اصلاح | ۱) دکمه pay-with-wallet در صورت insufficient balance غیرفعال می‌شود + پیام راهنما. ۲) `data-confirm` برای پرداخت کیف پول و ZarinPay. ۳) توضیح «بدون نیاز به احراز هویت» برای NOWPayments. |
| `resources/views/app/support/index.blade.php` | اصلاح | اضافه‌شدن `data-ticket-thread` برای auto-scroll به آخرین پیام. |
| `resources/views/admin/kyc/show.blade.php` | اصلاح | اضافه‌شدن `data-confirm` برای Approve، Changes Requested، Manual Attention. |
| `resources/views/admin/orders/show.blade.php` | اصلاح | ۱) فرم ثبت آمار با مقادیر قبلی pre-fill می‌شود + `as_of_at` پیش‌فرض now. ۲) `step="0.001"`. ۳) `data-confirm` روی دکمه Save. |
| `resources/views/admin/support/index.blade.php` | اصلاح | اضافه‌شدن `data-ticket-thread` برای auto-scroll. |
| `resources/views/admin/dashboard.blade.php` | اصلاح | جایگزینی `onchange="..."` با `data-auto-submit` (CSP-safe). |
| `resources/views/layouts/admin.blade.php` | اصلاح | اضافه‌شدن دکمه Logout در sidebar دسکتاپ. |
| `routes/console.php` | اصلاح | `telegram:webhook:set` حالا `allowed_updates = [message, callback_query]` را تنظیم می‌کند. |

---

## ۱.۵) حل مشکل «Telegram sign-in data is unavailable»

این بزرگ‌ترین بهبود این نسخه است. مشکل: Telegram تنها در صورتی `initData` را به مینی‌اپ تزریق می‌کند که کاربر از طریق دکمه‌ی inline web_app یا Menu Button پیکربندی‌شده در BotFather باز کند. اگر کاربر URL را مستقیم در چت تلگرام تایپ کند، یا از نسخه‌ی قدیمی تلگرام استفاده کند، `initData` خالی است و صفحه ورود برای همیشه روی خطا می‌ماند.

**راه‌حل سه‌لایه‌ی ما (به ترتیب اولویت):**

1. **لایه ۱ — `init_data` (امن):** همان روش قبلی، با HMAC. اگر تلگرام این داده را بفرستد، کاربر امن وارد می‌شود.
2. **لایه ۲ — `token` (توکن جادویی):** هر کاربر یک `magic_token` شخصی دارد که در URL دکمه‌ی inline قرار می‌گیرد: `https://domain/app?t=<token>`. حتی اگر `initData` خالی باشد، این توکن کاربر را شناسایی می‌کند. توکن در هر /start ریست می‌شود تا امنیت حفظ شود.
3. **لایه ۳ — `init_data_unsafe`:** اگر تلگرام فقط داده‌ی بدون امضا فرستاده باشد، از `initDataUnsafe.user.id` برای شناسایی کاربر استفاده می‌کنیم.

**مزیت:** کاربر دیگر نیازی به تنظیمات BotFather (Domain یا Configure Mini App) ندارد. کافی است ربات را در تلگرام باز کند، /start بفرستد، روی دکمه‌ی «📱 ورود به مینی‌اپ» بزند — تمام.

**صفحه ورود جدید:** اگر همه‌ی لایه‌ها شکست بخورند (کاربر URL را از خارج از تلگرام باز کرده)، صفحه ورود به‌جای نمایش خطای ساده، دکمه‌ی «ورود از تلگرام» را نشان می‌دهد که با `tg://resolve?domain=<bot>&start=start` ربات را در تلگرام باز می‌کند.

---

## ۲) اسکریپت‌ها (bin/)

| مسیر فایل | نوع تغییر | توضیح |
|---|---|---|
| `bin/install.sh` | بازنویسی کامل | نصب خودکار nginx + PHP + MariaDB + Node + certbot + pm2، ساخت کانفیگ nginx، گرفتن SSL با certbot، idempotent، ری‌استارت همه‌چیز، بررسی اتصال MariaDB قبل از migrations، fallback خودکار به SQLite در صورت شکست MariaDB، export `COMPOSER_ALLOW_SUPERUSER=1`. |
| `bin/update.sh` | بازنویسی کامل | git pull + composer + npm + migrate + cache + restart pm2/php-fpm/nginx، تمدید خودکار SSL با `certbot renew`، ثبت مجدد webhook، بررسی اتصال MariaDB قبل از migrations، export `COMPOSER_ALLOW_SUPERUSER=1`. |
| `bin/fix-mariadb-auth.sh` | جدید | اسکریپت تک‌مرحله‌ای برای رفع خطای `Host '127.0.0.1' is not allowed to connect` — کاربر را برای هر سه هاست `localhost`, `127.0.0.1`, `%` بازسازی می‌کند و یک connection test می‌گیرد. |

---

## ۳) مستندات (docs/)

| مسیر فایل | نوع تغییر | توضیح |
|---|---|---|
| `docs/SERVER_DEPLOYMENT.md` | بازنویسی کامل | توضیح نصب خودکار، متغیرهای محیطی جدید (`EMAIL_FOR_SSL`, `INSTALL_OS_PKGS`, `PHP_VERSION_PREFERENCE`)، رفع خطای Mini App initData با ثبت دامنه در BotFather، عیب‌یابی inline buttons. |

---

## ۴) فایل جدید در این پچ

| مسیر فایل | نوع | توضیح |
|---|---|---|
| `docs/CHANGES.md` | جدید | همین فایل — فهرست کامل تغییرات. |

---

## ۵) وابستگی‌های متقابل (Cross-cutting)

اگر بعداً قصد تغییر دارید، این روابط را در نظر بگیرید:

- **`TelegramWebhookController` ↔ `TelegramBotClient`**: کنترلر از کلیدهای خصوصی `callback_query_id`, `answer_text`, `answer_show_alert`, `edit_message_id` در `$options` استفاده می‌کند که در `TelegramBotClient::sendMessage` پردازش و سپس strip می‌شوند. اگر یکی را عوض کردید، حتماً در دیگری هم هماهنگ کنید.
- **`TelegramBotClient::setWebhook` ↔ `routes/console.php`**: `allowed_updates` در هر دو جا روی `['message', 'callback_query']` تنظیم شده. اگر نوع آپدیت جدیدی اضافه کردید (مثلاً `pre_checkout_query` برای Stars)، هر دو را هم‌زمان به‌روز کنید.
- **`TelegramWebhookController` ↔ `app/Models/User`**: کنترلر از ستون جدید `locale_set_at` (timestamp nullable) و متد `hasChosenLocale()` روی مدل `User` استفاده می‌کند تا بفهمد کاربر هنوز زبان انتخاب کرده یا نه. این مایگریشن جدید `2026_08_18_000001_add_locale_set_at_to_users_table.php` ستون را اضافه می‌کند.
- **`SendTelegramMessage` job ↔ `TelegramBotClient`**: job فقط `$options` را پاس می‌دهد؛ همه پردازش در client است.
- **`entry.blade.php` ↔ `app.js`**: صفحه blade attributeهای `data-*` را set می‌کند که JS آن‌ها را می‌خواند:
  - `data-miniapp-session`, `data-session-error`, `data-session-error-hint`, `data-session-retry`, `data-label-connect`, `data-label-retry`, `data-unavailable-hint`.
  - اگر یکی را در blade عوض کردید، در `app.js` هم به‌روز کنید.
- **`bin/install.sh` ↔ `bin/update.sh`**: update.sh سعی می‌کند از `.env` یا متغیر `APP_DOMAIN` دامنه را تشخیص دهد. اگر در install.sh نام متغیری را تغییر دادید، در update.sh هم هماهنگ کنید.
- **`bin/install.sh` ↔ `ecosystem.config.cjs`**: install.sh فرض می‌کند `ecosystem.config.cjs` در ریشه پروژه موجود است و دو پروسس `tgads-queue` و `tgads-sched` را تعریف می‌کند. اگر ساختار ecosystem را عوض کردید، در نصب و آپدیت هم بررسی کنید.

---

## ۶) اقدامات پس از اعمال پچ

1. `git pull` یا جایگزینی دستی فایل‌ها از ZIP.
2. `sudo bash bin/update.sh` — این کار composer + npm + migrate + cache + restart pm2/nginx/php-fpm + certbot renew + ثبت مجدد webhook را با هم انجام می‌دهد.
3. در BotFather، مطمئن شوید دامنه‌ی سرور را در `/mybots → Bot Settings → Domain → Add Domain` ثبت کرده‌اید (تا `initData` در Mini App کار کند).
4. مطمئن شوید Menu Button به `https://<APP_DOMAIN>/app` تنظیم شده است.
5. اگر اولین بار است که نصب می‌کنید، `sudo APP_DOMAIN=... EMAIL_FOR_SSL=... bash bin/install.sh` را اجرا کنید تا تمام پیش‌نیازها نصب شوند.

---

## ۷) خلاصه رفع مشکل Mini App ("Telegram sign-in data is unavailable")

علت اصلی: صفحه ورود به‌محض لود شدن، `telegram.initData` را می‌خواند. در برخی کلاینت‌های تلگرام (Telegram Desktop، نسخه‌های قدیمی اندروید، یا وقتی کاربر از مرورگر باز می‌کند) این فیلد تا قبل از fire شدن `ready` خالی است و برای همیشه روی صفحه خطا می‌ماند.

رفع در این پچ:

- **JS**: از `telegram.ready(callback)` استفاده می‌کنیم تا قبل از خواندن initData صبر کنیم. اگر initData بعد از ready هم خالی باشد، دکمه «تلاش دوباره» نمایش داده می‌شود تا کاربر بعد از رفع علت (مثلاً ثبت دامنه در BotFather) بتواند بدون restart تلگرام، دوباره امتحان کند.
- **راهنمای روی صفحه**: متن خطا به‌جای «این صفحه را از دکمه ربات باز کنید»، حالا توضیح می‌دهد که دکمه Retry را بزنید و در صورت تداوم، دامنه را در BotFather ثبت کنید.
- **common cause**: مطمئن شوید **دامنه‌ی سرور در BotFather ثبت شده** (`/mybots → Bot Settings → Domain → Add Domain`) و Mini App از داخل تلگرام باز می‌شود (نه با URL مستقیم در مرورگر).
