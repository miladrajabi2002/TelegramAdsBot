# راه‌اندازی روی سرور (نصب خودکار + PM2 + SSL + nginx)

این راهنما استقرار پروژه روی یک سرور لینوکس (Ubuntu/Debian با nginx + PHP-FPM + MariaDB/MySQL) را پوشش می‌دهد. همه مراحل با یک دستور اجرا می‌شود و اسکریپت idempotent است؛ یعنی می‌توانید آن را دوباره و دوباره اجرا کنید بدون اینکه چیزی خراب شود.

> **ایمیل غیرفعال است.** `MAIL_MAILER=log` تنظیم شده و نیازی به SMTP نیست.

---

## ۰) پیش‌نیازهای یک‌بار

تنها چیزی که نیاز دارید:

- سرور Ubuntu 22/24 یا Debian 12 با دسترسی sudo/root.
- یک دامنه که رکورد A آن به IP سرور اشاره می‌کند.

**بقیه کارها (نصب nginx، PHP، MySQL، Node، certbot، pm2، گرفتن SSL، ساخت کانفیگ nginx، اجرای migrations، تنظیم permissions و ثبت webhook تلگرام) همه توسط `bin/install.sh` خودکار انجام می‌شود.**

اگر ترجیح می‌دهید پکیج‌های سیستم را دستی نصب کنید، می‌توانید با `INSTALL_OS_PKGS=0` اجرا کنید:

```bash
sudo INSTALL_OS_PKGS=0 APP_DOMAIN=bot.example.com bash bin/install.sh
```

---

## ۱) دریافت پروژه

```bash
sudo mkdir -p /var/www/TelegramAdsBot
sudo chown -R $USER:$USER /var/www/TelegramAdsBot
git clone https://github.com/miladrajabi2002/TelegramAdsBot.git /var/www/TelegramAdsBot
cd /var/www/TelegramAdsBot
```

---

## ۲) نصب یک‌کلیک

```bash
sudo APP_DOMAIN=bot.example.com \
     EMAIL_FOR_SSL=you@example.com \
     [TELEGRAM_BOT_TOKEN=...] \
     bash bin/install.sh
```

### این اسکریپت دقیقاً چه کار می‌کند؟

1. **نصب پکیج‌های سیستم** (تنها در صورت نبودن):
   - `nginx`, `mariadb-server`
   - `php8.4-fpm` + پسوندها (curl, mbstring, xml, mysql, intl, bcmath, gd, zip, sqlite3, opcache)
   - `nodejs` (نسخه 20 از NodeSource) + `npm`
   - `certbot` + `python3-certbot-nginx`
2. **غیرفعال‌کردن `php-psr`** (با `psr/log` Monolog تداخل دارد).
3. **composer install** + **npm ci** + **npm run build**.
4. **ساخت `.env`** با secretهای تصادفی (در صورت نبود) و ساخت خودکار دیتابیس + کاربر MySQL.
5. **migrate --seed**, **storage:link**, **config/route/view cache**.
6. **تنظیم permissions** روی `storage/` و `bootstrap/cache/` و `.env`.
7. **ساخت کانفیگ nginx** در `/etc/nginx/sites-available/<domain>.conf` و فعال‌کردن آن. اگر کانفیگ از قبل وجود دارد، دست‌نخورده باقی می‌ماند.
8. **گرفتن SSL** با `certbot --nginx -d <domain>` اگر:
   - هنوز سرتیفیکیت برای آن دامنه صادر نشده، و
   - DNS دامنه به IP همین سرور اشاره کند.
   در غیر این صورت، مرحله رد می‌شود تا بعداً (مثلاً بعد از اصلاح DNS) دوباره اجرا کنید.
9. **نصب/راه‌اندازی PM2** با `ecosystem.config.cjs` + `pm2 save` + `pm2 startup`.
10. **ثبت webhook تلگرام** اگر `TELEGRAM_BOT_TOKEN` تنظیم باشد. `allowed_updates` شامل `message` و `callback_query` است تا inline buttons کار کنند.

### متغیرهای محیطی قابل پاس به install.sh

| متغیر | پیش‌فرض | توضیح |
|---|---|---|
| `APP_DOMAIN` | `bot.miladrajabi.com` | دامنه‌ای که روی آن نصب می‌شود. |
| `APP_URL` | `https://$APP_DOMAIN` | URL کامل. |
| `EMAIL_FOR_SSL` | `admin@$APP_DOMAIN` | ایمیل ثبت‌نام در Let's Encrypt. |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `telegram_ads_bot` / `tgadsbot` / random | اطلاعات دیتابیس. |
| `TELEGRAM_BOT_TOKEN` | (تنظیم نشده) | توکن ربات — اگر پاس بدید، webhook خودکار ثبت می‌شود. |
| `TELEGRAM_BOT_USERNAME` | (تنظیم نشده) | username بدون `@`. |
| `TELEGRAM_WEBHOOK_SECRET` | random | در صورت نبود، خودکار تولید می‌شود. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@$APP_DOMAIN` / random | لاگین اولیه ادمین. |
| `INSTALL_OS_PKGS` | `1` | اگر `0` باشد، apt-install انجام نمی‌شود (مفید وقتی پکیج‌ها را خودتان نصب کرده‌اید). |
| `PHP_VERSION_PREFERENCE` | `8.4 8.3` | ترتیب نسخه‌های PHP برای انتخاب/نصب. |
| `WEB_USER` | `www-data` | کاربری که باید owner فایل‌های storage باشد. |

---

## ۳) پروسس‌های PM2

| نام        | دستور                          | کار                              |
|------------|--------------------------------|----------------------------------|
| tgads-queue| `php artisan queue:work`       | کارگر صف دیتابیس (بلاست/پیام تلگرام) |
| tgads-sched| `php artisan schedule:work`   | اجرای زمانبند هر دقیقه           |

دستورهای کاربردی:

```bash
pm2 status
pm2 logs tgads-queue
pm2 restart tgads-queue
pm2 monit
```

---

## ۴) به‌روزرسانی پس از تغییر کد

```bash
cd /var/www/TelegramAdsBot
sudo bash bin/update.sh
```

این اسکریپت:

1. `git pull --ff-only`
2. `composer install` + `npm ci` + `npm run build`
3. `php artisan migrate`, `config:cache`, `route:cache`, `view:cache`
4. تنظیم دسترسی‌ها
5. **restart `php-fpm`** + **reload `nginx`** + **restart `pm2`**
6. **`certbot renew`** (بدون تعامل؛ اگر سرتیفیکیت هنوز منقضی نشده باشد، no-op است)
7. **ثبت مجدد webhook تلگرام** (تا `allowed_updates` با کد جدید هماهنگ بماند)

بنابراین پس از هر `git pull` و `bash bin/update.sh`، کل سیستم در وضعیت سالم و هماهنگ است.

---

## ۵) ثبت Mini App در BotFather

پس از نصب و فعال‌بودن SSL، Mini App را در BotFather ثبت کنید:

```
/mybots → انتخاب ربات → Bot Settings → Configure Mini App → Enable Mini App
URL: https://<APP_DOMAIN>/app
```

و دکمه منو را به همان URL وصل کنید:

```
/setmenubutton
```

**همچنین حتماً دامنه را در BotFather ثبت کنید** (این مرحله برای این است که `initData` در Mini App پر شود):

```
/mybots → انتخاب ربات → Bot Settings → Domain → Add Domain
Domain: <APP_DOMAIN>   (مثلاً bot.example.com — بدون https://)
```

> اگر این مرحله را انجام ندهید، Mini App باز می‌شود ولی `initData` خالی می‌ماند و صفحه ورود خطای "Telegram sign-in data is unavailable" نشان می‌دهد. در صفحه ورود دکمه «تلاش دوباره» قرار داده شده تا کاربر بتواند بعد از ثبت دامنه در BotFather بدون نیاز به restart تلگرام، دوباره امتحان کند.

لینک شروع:

```
https://t.me/<BOT_USERNAME>?startapp
```

---

## ۶) راهنمای متغیرهای محیطی (.env)

`.env` به‌صورت خودکار با مقادیر تصادفی ساخته می‌شود. متغیرهایی که **باید دستی تنظیم شوند**:

| متغیر | از کجا | توضیح |
|---|---|---|
| `APP_URL` | دامنه شما | `https://bot.example.com` — baseURL برای webhook و Mini App. |
| `APP_DOMAIN` (نصب) | دامنه | `bot.example.com`. |
| `TELEGRAM_BOT_TOKEN` | BotFather (@BotFather → /newbot یا /token) | توکن ربات. فقط در `.env` و هرگز در گیت. |
| `TELEGRAM_BOT_USERNAME` | BotFather | username بدون `@`. |
| `TELEGRAM_WEBHOOK_SECRET` | خودکار در نصب تولید می‌شود | رشته تصادفی؛ در ثبت webhook به‌عنوان `secret_token` به تلگرام ارسال می‌شود. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | خودکار در نصب | لاگین اولیه ادمین. رمز را تغییر دهید یا بعداً از پنل عوض کنید. |
| `KYC_HMAC_KEY` | خودکار در نصب | کلید HMAC برای جستجوی امن ملی/کارت. |
| `NOWPAYMENTS_API_KEY` | پنل NowPayments → API Keys | کلید API درگاه. |
| `NOWPAYMENTS_PUBLIC_KEY` | پنل NowPayments → Public Keys | برای تأیید امضای IPN. |
| `NOWPAYMENTS_IPN_SECRET` | پنل NowPayments → IPN secret | راز مشترک برای اعتبارسنجی IPN. |
| `ZARINPAY_ACCESS_TOKEN` | پنل ZarinPay/Zarinmee | توکن درگاه ریالی (اختیاری تا فعال‌سازی). |
| `ADS_PLATFORM_CHANNEL_USERNAME` / `_SUPPORT_USERNAME` | کانال/پشتیبانی شما | username بدون `@`. |

پس از ویرایش `.env`:

```bash
sudo bash bin/update.sh
```

(این دستور config:cache + restart pm2 + reload nginx را با هم انجام می‌دهد.)

---

## ۷) آدرس‌ها و URLها

| مورد | URL |
|---|---|
| Mini App (صفحه مشتری) | `https://bot.example.com/app` |
| پنل ادمین | `https://bot.example.com/admin/login` |
| Webhook تلگرام | `https://bot.example.com/webhooks/telegram` |
| IPN درگاه NowPayments | `https://bot.example.com/webhooks/nowpayments` |
| Callback درگاه ZarinPay | `https://bot.example.com/payments/zarinpay/callback` |
| Health check | `https://bot.example.com/healthz` |

---

## ۸) ورود به پنل ادمین

1. به `https://bot.example.com/admin/login` بروید.
2. با `ADMIN_EMAIL` و `ADMIN_PASSWORD` که هنگام نصب چاپ شدند وارد شوید (مقادیر در `.env` هم هستند).
3. نقش پیش‌فرض `super_admin` با دسترسی کامل است.

تغییر رمز ادمین پس از اولین ورود از پنل یا با:

```bash
php artisan tinker
>>> App\Models\Admin::where('email','admin@bot.example.com')->first()->update(['password' => 'new-secret']);
```

---

## ۹) ثبت patch و webhookها

### ۹-۱) ثبت webhook تلگرام

وقتی `TELEGRAM_BOT_TOKEN` را در `.env` گذاشتید:

```bash
sudo bash bin/update.sh
# یا فقط webhook را دوباره ثبت کنید:
sudo php artisan config:cache
sudo php artisan telegram:webhook:set
```

این دستور `setWebhook` را با آدرس `https://bot.example.com/webhooks/telegram` و `secret_token` (همان `TELEGRAM_WEBHOOK_SECRET`) ثبت می‌کند.

بررسی وضعیت webhook:

```bash
curl -s "https://api.telegram.org/bot<YOUR_TOKEN>/getWebhookInfo" | jq
```

> **توجه:** تلگرام فقط با HTTPS و گواهی معتبر کار می‌کند. مطمئن شوید DNS دامنه به این سرور اشاره می‌کند و گواهی SSL فعال است.

### ۹-۲) ثبت Mini App در BotFather

```
/mybots → انتخاب ربات → Bot Settings → Configure Mini App → Enable Mini App
URL: https://bot.example.com/app
```

سپس Menu Button را از Bot Settings یا /setmenubutton به همان URL وصل کنید. برای Main Mini App می‌توان preview چندزبانه نیز بارگذاری کرد.

**حتماً دامنه را در BotFather ثبت کنید** (تا initData کار کند):

```
/mybots → انتخاب ربات → Bot Settings → Domain → Add Domain
```

لینک شروع:

```
https://t.me/<BOT_USERNAME>?startapp
```

### ۹-۳) ثبت patch پرداخت در NowPayments (IPN)

1. در پنل NowPayments به **Account → API Keys** بروید و `API Key` و `Public Key` را بردارید.
2. به **Account → IPN settings** بروید و **IPN secret** را بسازید.
3. آدرس IPN را ثبت کنید:

   ```
   https://bot.example.com/webhooks/nowpayments
   ```

4. این سه مقدار را در `.env` بگذارید:

   ```env
   NOWPAYMENTS_API_KEY=...
   NOWPAYMENTS_PUBLIC_KEY=...
   NOWPAYMENTS_IPN_SECRET=...
   NOWPAYMENTS_ENABLED=true
   ```

5. اعمال:

   ```bash
   sudo bash bin/update.sh
   ```

> «patch» درگاه یعنی همین ثبت endpoint/IPN در پنل NowPayments و فعال‌کردن آن در `.env`. تا زمانی که `NOWPAYMENTS_ENABLED=true` نشود، مسیر پرداخت ارزی فعال نخواهد بود.

### ۹-۴) فعال‌سازی درگاه ریالی ZarinPay (اختیاری)

```env
ZARINPAY_ACCESS_TOKEN=...
ZARINPAY_ENABLED=true
```

```bash
sudo bash bin/update.sh
```

---

## ۱۰) بک‌آپ

- دیتابیس: `mysqldump telegram_ads_bot > backup.sql`
- مدارک KYC (خصوصی): `storage/app/private`
- فایل `.env`.

بک‌آپ خودکار را با cron روزانه تنظیم کنید. تمدید خودکار SSL نیز با cron certbot انجام می‌شود (certbot این رکورد cron را خودش می‌سازد):

```bash
sudo systemctl list-timers | grep certbot
```

---

## ۱۱) عیب‌یابی

### ۱۱-۱) خطای `Host '127.0.0.1' is not allowed to connect to this MariaDB server`

این **شایع‌ترین** مشکل نصب است. علت: در MariaDB، `localhost` (اتصال از طریق Unix socket) و `127.0.0.1` (اتصال از طریق TCP) به‌عنوان دو هاست متفاوت در نظر گرفته می‌شوند. یعنی کاربر `'tgadsbot'@'localhost'` می‌تواند از طریق socket وصل شود ولی اگر `DB_HOST=127.0.0.1` در `.env` باشد، اتصال از طریق TCP انجام می‌شود و آن کاربر اجازه ندارد.

#### راه‌حل سریع (یک دستور)

```bash
sudo bash bin/fix-mariadb-auth.sh
```

این اسکریپت کاربر را برای هر سه هاست `localhost`، `127.0.0.1` و `%` با همان رمز `.env` بازسازی می‌کند و در پایان یک connection test از طریق TCP و socket می‌گیرد تا مطمئن شویم درست شده. سپس:

```bash
sudo bash bin/install.sh
# یا فقط به‌روزرسانی:
sudo bash bin/update.sh
```

#### راه‌حل دستی

اگر می‌خواهید دستی انجام دهید:

```bash
sudo mysql
```

```sql
-- دیدن کاربران موجود:
SELECT User, Host FROM mysql.user WHERE User='tgadsbot';

-- حذف و بازسازی برای هر سه هاست:
DROP USER IF EXISTS 'tgadsbot'@'localhost';
DROP USER IF EXISTS 'tgadsbot'@'127.0.0.1';
DROP USER IF EXISTS 'tgadsbot'@'%';

CREATE USER 'tgadsbot'@'localhost'  IDENTIFIED BY '<password_from_.env>';
CREATE USER 'tgadsbot'@'127.0.0.1'  IDENTIFIED BY '<password_from_.env>';
CREATE USER 'tgadsbot'@'%'          IDENTIFIED BY '<password_from_.env>';

GRANT ALL PRIVILEGES ON `telegram_ads_bot`.* TO 'tgadsbot'@'localhost';
GRANT ALL PRIVILEGES ON `telegram_ads_bot`.* TO 'tgadsbot'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `telegram_ads_bot`.* TO 'tgadsbot'@'%';
FLUSH PRIVILEGES;
```

سپس:

```bash
sudo bash bin/update.sh
```

> **نکته:** `bin/install.sh` (نسخه جدید) این کار را خودکار انجام می‌دهد، ولی فقط وقتی بتواند به‌عنوان root از طریق Unix socket به MariaDB وصل شود. اگر برای root رمز گذاشته‌اید، یا `sudo mysql` به شما دسترسی نمی‌دهد، آن‌را دستی اجرا کنید یا `MYSQL_ROOT_PASSWORD` را به‌صورت env پاس بدهید.

### ۱۱-۲) سایر خطاها

| مشکل | راه‌حل |
|---|---|
| `500` در سایت | `tail -f storage/logs/laravel.log` و بررسی `.env` و دسترسی‌های storage. |
| `Driver [database] not supported` | `APP_MAINTENANCE_DRIVER=file` در `.env`. |
| خطای `Monolog\Logger ... PsrExt` | پسوند `php-psr` را غیرفعال کنید (`phpdismod psr`) — `install.sh` این کار را خودکار می‌کند. |
| Mini App: "Telegram sign-in data is unavailable" | ۱) مطمئن شوید دامنه را در BotFather ثبت کرده‌اید (`/mybots` → Domain). ۲) Mini App را از داخل تلگرام باز کنید، نه از مرورگر. ۳) دکمه «تلاش دوباره» را بزنید. |
| تلگرام webhook به‌روزرسانی نمی‌کند | بررسی `getWebhookInfo`؛ مطمئن شوید DNS/SSL درست و `TELEGRAM_WEBHOOK_SECRET` تنظیم است. |
| صف کار نمی‌کند | `pm2 logs tgads-queue`؛ مطمئن شوید `QUEUE_CONNECTION=database`. |
| زمانبد اجرا نمی‌شود | `pm2 logs tgads-sched`. |
| Inline buttons جواب نمی‌دهند | مطمئن شوید `allowed_updates` شامل `callback_query` است (`php artisan telegram:webhook:set` این را خودکار تنظیم می‌کند). |
| SSL تمدید نمی‌شود | `sudo certbot renew --dry-run` و بررسی `/var/log/letsencrypt/letsencrypt.log`. |
| `Composer plugins have been disabled for safety in this non-interactive session` | اسکریپت `install.sh` و `update.sh` خودشان `COMPOSER_ALLOW_SUPERUSER=1` را export می‌کنند. اگر دستی composer اجرا می‌کنید، خودتان این متغیر را set کنید. |
| `npm warn EBADENGINE ... required: { node: '>=22' }` | هشدار بی‌زیان است؛ نسخه 20 کاملاً کار می‌کند. اگر نصب با موفقیت تمام شد، می‌توانید نادیده بگیرید. |
