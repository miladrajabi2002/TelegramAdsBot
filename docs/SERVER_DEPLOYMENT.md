# راه‌اندازی روی سرور (یک‌کلیک + PM2)

این راهنما استقرار پروژه روی یک سرور لینوکس (Ubuntu/Debian با nginx + PHP-FPM + MariaDB/MySQL) را پوشش می‌دهد. همه مراحل با یک دستور اجرا می‌شود و می‌توانید آن را دوباره هم اجرا کنید (idempotent).

> **ایمیل غیرفعال است.** `MAIL_MAILER=log` تنظیم شده و نیازی به SMTP نیست.

---

## ۰) پیش‌نیازهای یک‌بار

- سرور Ubuntu 22/24 یا Debian 12.
- PHP 8.3 یا بالاتر (توصیه 8.4) با PHP-FPM.
- nginx با SSL معتبر (مثلاً Certbot).
- MariaDB/MySQL 10.6+.
- Node.js 18+ و npm.
- یک دامنه با رکورد DNS (مثلاً `bot.example.com` → IP سرور) و گواهی SSL برای آن.

اگر PHP و nginx نصب نیست:

```bash
sudo apt update
sudo apt install -y nginx mariadb-server php8.4 php8.4-fpm php8.4-mysql php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-intl php8.4-bcmath php8.4-gd php8.4-zip php8.4-sqlite3 \
  composer nodejs npm certbot python3-certbot-nginx
sudo npm install -g pm2
```

گواهی SSL:

```bash
sudo certbot --nginx -d bot.example.com
```

---

## ۱) دریافت پروژه

```bash
sudo mkdir -p /var/www/example.com/bot
sudo chown -R $USER:$USER /var/www/example.com/bot
git clone https://github.com/miladrajabi2002/TelegramAdsBot.git /var/www/example.com/bot/TelegramAdsBot
cd /var/www/example.com/bot/TelegramAdsBot
```

---

## ۲) نصب یک‌کلیک

```bash
sudo APP_DOMAIN=bot.example.com bash bin/install.sh
```

اسکریپت `bin/install.sh` این کارها را انجام می‌دهد:

1. بررسی و نصب پسوندهای PHP مورد نیاز (از جمله `tokenizer`، `xmlwriter`، `pdo_sqlite`) و غیرفعال‌کردن `php-psr` (با `psr/log` درگیر می‌شود).
2. `composer install` و `npm ci` + `npm run build`.
3. ساخت `.env` با secretهای تصادفی (در صورت نبود) و ساخت خودکار دیتابیس + کاربر MySQL.
4. `php artisan migrate --seed`، `storage:link` و کش‌سازی config/route/view.
5. تنظیم دسترسی‌های `storage` و `bootstrap/cache`.
6. نصب **PM2** اگر نصب نیست، اجرای `ecosystem.config.cjs`، `pm2 save` و فعال‌کردن `pm2 startup` (اجرای خودکار پس از ری‌بوت).
7. ثبت **webhook تلگرام** در صورتی که `TELEGRAM_BOT_TOKEN` تنظیم باشد.

پس از پایان، خلاصه شامل آدرس پنل ادمین، Mini App و آدرس webhook چاپ می‌شود.

### پروسس‌های PM2

| نام        | دستور                          | کار                              |
|------------|--------------------------------|----------------------------------|
| tgads-queue| `php artisan queue:work`       | کارگر صف دیتابیس (بلاست/پیام)     |
| tgads-sched| `php artisan schedule:work`   | اجرای زمانبند هر دقیقه           |

دستورهای کاربردی:

```bash
pm2 status
pm2 logs tgads-queue
pm2 restart tgads-queue
pm2 monit
```

یک رکورد cron هم به‌عنوان شبکه‌ای امن اضافه می‌شود (`/etc/cron.d/telegram-ads-bot`) که اگر PM2 پایین بود، زمانبند باز هم اجرا شود.

---

## ۳) nginx (نمونه)

```nginx
server {
    listen 80;
    server_name bot.example.com;
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl http2;
    server_name bot.example.com;

    root /var/www/example.com/bot/TelegramAdsBot/public;
    index index.php;
    client_max_body_size 24M;

    ssl_certificate     /etc/letsencrypt/live/bot.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/bot.example.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    location = /healthz { return 200 "ok\n"; add_header Content-Type text/plain; }
    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known).* { deny all; }
    location ~* \.(env|json|lock|md|yml|yaml)$ { deny all; }
}
```

بررسی و اعمال:

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -k https://bot.example.com/healthz   # → ok
```

---

## ۴) راهنمای متغیرهای محیطی (.env)

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
sudo php artisan config:cache
sudo pm2 restart tgads-queue tgads-sched
```

---

## ۵) آدرس‌ها و URLها

| مورد | URL |
|---|---|
| Mini App (صفحه مشتری) | `https://bot.example.com/app` |
| پنل ادمین | `https://bot.example.com/admin/login` |
| Webhook تلگرام | `https://bot.example.com/webhooks/telegram` |
| IPN درگاه NowPayments | `https://bot.example.com/webhooks/nowpayments` |
| Callback درگاه ZarinPay | `https://bot.example.com/payments/zarinpay/callback` |
| Health check | `https://bot.example.com/healthz` |

---

## ۶) ورود به پنل ادمین

1. به `https://bot.example.com/admin/login` بروید.
2. با `ADMIN_EMAIL` و `ADMIN_PASSWORD` که هنگام نصب چاپ شدند وارد شوید (مقادیر در `.env` هم هستند).
3. نقش پیش‌فرض `super_admin` با دسترسی کامل است.

تغییر رمز ادمین پس از اولین ورود از پنل یا با:

```bash
php artisan tinker
>>> App\Models\Admin::where('email','admin@bot.example.com')->first()->update(['password' => 'new-secret']);
```

ایجاد ادمین جدید:

```bash
php artisan tinker
>>> App\Models\Admin::create([
...   'name' => 'Operator', 'email' => 'op@example.com',
...   'password' => 'secret', 'role' => 'operator',
...   'permissions' => ['orders.view','orders.manage'], 'is_active' => true,
... ]);
```

---

## ۷) ثبت patch و webhookها

### ۷-۱) ثبت webhook تلگرام

وقتی `TELEGRAM_BOT_TOKEN` را در `.env` گذاشتید:

```bash
sudo php artisan config:cache
sudo php artisan telegram:webhook:set
```

این دستور `setWebhook` را با آدرس `https://bot.example.com/webhooks/telegram` و `secret_token` (همان `TELEGRAM_WEBHOOK_SECRET`) ثبت می‌کند.

بررسی وضعیت webhook:

```bash
curl -s "https://api.telegram.org/bot<YOUR_TOKEN>/getWebhookInfo" | jq
```

> **توجه:** تلگرام فقط با HTTPS و گواهی معتبر کار می‌کند. مطمئن شوید DNS دامنه به این سرور اشاره می‌کند و گواهی SSL فعال است.

### ۷-۲) ثبت Mini App در BotFather

```
/mybots → انتخاب ربات → Bot Settings → Configure Mini App → Enable Mini App
URL: https://bot.example.com/app
```

دکمه منو را به همین URL وصل کنید (`/setmenubutton`). لینک شروع:

```
https://t.me/<BOT_USERNAME>?startapp
```

### ۷-۳) ثبت patch پرداخت در NowPayments (IPN)

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
   sudo php artisan config:cache
   sudo pm2 restart tgads-queue tgads-sched
   ```

> «patch» درگاه یعنی همین ثبت endpoint/IPN در پنل NowPayments و فعال‌کردن آن در `.env`. تا زمانی که `NOWPAYMENTS_ENABLED=true` نشود، مسیر پرداخت ارزی فعال نخواهد بود.

### ۷-۴) فعال‌سازی درگاه ریالی ZarinPay (اختیاری)

```env
ZARINPAY_ACCESS_TOKEN=...
ZARINPAY_ENABLED=true
```

```bash
sudo php artisan config:cache && sudo pm2 restart tgads-queue tgads-sched
```

---

## ۸) به‌روزرسانی پس از تغییر کد

```bash
cd /var/www/example.com/bot/TelegramAdsBot
sudo bash bin/update.sh
```

این اسکریپت `git pull`، build فرانت، migrate و restart PM2 را با هم انجام می‌دهد.

---

## ۹) بک‌آپ

- دیتابیس: `mysqldump telegram_ads_bot > backup.sql`
- مدارک KYC (خصوصی): `storage/app/private`
- فایل `.env`.

بک‌آپ خودکار را با cron روزانه تنظیم کنید.

---

## ۱۰) عیب‌یابی

| مشکل | راه‌حل |
|---|---|
| `500` در سایت | `tail -f storage/logs/laravel.log` و بررسی `.env` و دسترسی‌های storage. |
| `Driver [database] not supported` | `APP_MAINTENANCE_DRIVER=file` در `.env`. |
| خطای `Monolog\Logger ... PsrExt` | پسوند `php-psr` را غیرفعال کنید (`phpdismod psr`). |
| تله‌گرام webhook به‌روزرسانی نمی‌کند | بررسی `getWebhookInfo`؛ مطمئن شوید DNS/SSL درست و `TELEGRAM_WEBHOOK_SECRET` تنظیم است. |
| صف کار نمی‌کند | `pm2 logs tgads-queue`؛ مطمئن شوید `QUEUE_CONNECTION=database`. |
| زمانبند اجرا نمی‌شود | `pm2 logs tgads-sched` و بررسی `/etc/cron.d/telegram-ads-bot`. |
