# لیست تغییرات — TelegramAdsBot

این فهرست تمام فایل‌هایی است که در این پچ تغییر کرده یا اضافه شده‌اند. مسیرها نسبی به ریشه پروژه هستند و در فایل ZIP با همین ساختار قرار گرفته‌اند تا بتوانید به‌صورت دستی روی نسخه محلی خود جایگزین کنید.

> **روش جایگزینی:** محتوای ZIP را در ریشه پروژه‌ی خود (کنار `composer.json`) extract کنید. همه فایل‌ها با ساختار اصلی overwrite می‌شوند. سپس `sudo bash bin/update.sh` را اجرا کنید تا تغییرات اعمال شود (composer + npm + migrate + cache + restart pm2/nginx/php-fpm).

---

## ۱) ریشه‌دار (Root-level)

| مسیر فایل | نوع تغییر | توضیح |
|---|---|---|
| `app/Http/Controllers/TelegramWebhookController.php` | اصلاح اساسی | پشتیبانی از `callback_query` (دکمه‌های inline)، انتخاب زبان فقط یک بار (با رصد `locale_set_at`)، منوی اصلی با inline buttons به جای keyboard. |
| `app/Http/Controllers/MiniApp/SessionController.php` | اصلاح | locale را فقط در صورب ذخیره می‌کند که کاربر هنوز انتخاب نکرده باشد. وقتی کاربر از داخل Mini App زبان را عوض می‌کند، `locale_set_at` را هم set می‌کند تا ربات بداند دیگر نباید بپرسد. |
| `app/Models/User.php` | اصلاح | اضافه‌شدن `locale_set_at` به fillable و casts، و متد کمکی `hasChosenLocale()`. |
| `database/migrations/2026_08_18_000001_add_locale_set_at_to_users_table.php` | جدید | مایگریشن افزودن ستون `locale_set_at` (timestamp nullable) به جدول `users`. |
| `app/Services/Telegram/TelegramBotClient.php` | اصلاح | اضافه‌شدن `answerCallbackQuery` داخلی در `sendMessage`، کنترل `disable_web_page_preview`، و `allowed_updates = [message, callback_query]` در `setWebhook`. |
| `app/Jobs/SendTelegramMessage.php` | بدون تغییر منطقی | فقط commentها کوتاه شد تا گزینه‌های جدید در `TelegramBotClient` به‌خوبی دیده شوند. |
| `resources/js/app.js` | اصلاح اساسی | استفاده از `telegram.ready(callback)` به‌جای `ready()` همگام، اضافه‌شدن دکمه «تلاش دوباره» در صفحه ورود، تشخیص بهتر زمان در دسترس نبودن initData. |
| `resources/views/app/entry.blade.php` | اصلاح | اضافه‌شدن دکمه Retry و راهنمای «دامنه را در BotFather ثبت کنید» وقتی initData خالی است. |
| `resources/views/components/icon.blade.php` | اصلاح | اضافه‌شدن آیکون جدید `refresh` برای دکمه Retry. |
| `routes/console.php` | اصلاح | `telegram:webhook:set` حالا `allowed_updates = [message, callback_query]` را تنظیم می‌کند تا inline buttons کار کنند. |

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
