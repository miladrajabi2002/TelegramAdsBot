# Telegram Ads Bot — پلتفرم سفارش تبلیغات تلگرام (Exir Ads)

پلتفرم دوزبانه (فارسی/انگلیسی) برای دریافت سفارش تبلیغات تلگرام که شامل **ربات تلگرام + Mini App** برای مشتریان و **پنل مدیریت وب** برای تیم پشتیبانی و اپراتورهاست. مشتری در Mini App سفارش تبلیغ خود را ثبت می‌کند، هزینه را از کیف پول یا درگاه پرداخت می‌دهد و روند بررسی، تأیید، ثبت در Telegram Ads و گزارش‌گیری به‌صورت کامل در پنل مدیریت دنبال می‌شود.

> این سرویس مستقل و غیررسمی است و متعلق به Telegram یا نماینده/شریک رسمی Telegram Ads نیست. نسخه اول اپراتورمحور است: ثبت کمپین در پنل رسمی Telegram Ads و انتقال وضعیت و آمار توسط اپراتور انجام می‌شود.

---

## امکانات

- **ورود بدون رمز** با داده امضاشده Telegram Mini App (`initData`) و توکن magic-link برای احراز هویت پایدار.
- رابط دوزبانه فارسی راست‌به‌چپ / انگلیسی چپ‌به‌راست.
- **ثبت سفارش تبلیغ** با ویزارد چندمرحله‌ای: متن تبلیغ، لینک مقصد، انتخاب کانال/ربات هدف (از کاتالوگ یا دستی)، بودجه، پلن (استاندارد/رقابتی)، CPM به GRAM و محدودیت بازدید روزانه.
- **نسخه‌بندی و اصلاح**: بعد از درخواست اصلاح توسط پشتیبانی، مشتری سفارش را ویرایش کرده و نسخه جدید (revision) برای بررسی مجدد ارسال می‌کند.
- **اعلان‌های اکانت سودو (sudo)**: با ثبت هر تبلیغ جدید یا ارسال مجدد نسخه اصلاح‌شده، فوری پیام هشدار به اکانت تلگرام مالک (`TELEGRAM_SODO_ID`) ارسال می‌شود تا هیچ سفارشی از دید پشتیبانی دور نماند.
- احراز هویت اجباری (KYC) پیش از واریز ریالی: شماره همراه، کارت ملی، تصویر شخص با کارت ملی، نام صاحب حساب و کارت بانکی.
- کیف پول داخلی و دفترکل دوطرفه با رزرو وجه سفارش و تراکنش idempotent.
- پرداخت مستقیم سفارش یا افزایش کیف پول — **ZarinPay (ریالی)** و **NowPayments (ارزی/کریپتو)**.
- بررسی محتوا توسط پشتیبانی و صف اپراتور برای ثبت دستی در Telegram Ads.
- قیمت‌گذاری پویا با نرخ لحظه‌ای (USDT/IRT و TON/USDT از API عمومی Exir) + fallback به نرخ ذخیره‌شده.
- ثبت دستی وضعیت و snapshotهای آمار تجمعی هر سفارش.
- پنل ادمین: داشبورد، سفارش‌ها، KYC، کاربران، تراکنش‌ها، کاتالوگ کانال، بلاست همگانی (broadcast)، تیکت پشتیبانی، audit log و تنظیمات.
- صف دیتابیس (سازگار با هاست اشتراکی) و زمانبند (PM2).

مبلغ ریالی با واحد IRR (صحیح) ذخیره می‌شود؛ نمایش تومان فقط تبدیل نمایشی (`/10`) است. کارمزد پیش‌فرض ۱۵۰۰ basis point (۱۵٪) است.

---

## چرخه حیات سفارش تبلیغ

```
draft → awaiting_payment → support_review → queued_for_telegram → telegram_review
                                    ↑↓ (changes_requested ⇄ support_review)
         telegram_review → telegram_approved → scheduled → active → completed
```

- مشتری سفارش را ثبت می‌کند (`awaiting_payment`) ← اعلان سودو 🆕
- پس از پرداخت، سفارش به بررسی پشتیبانی می‌رود (`support_review`)
- در صورت نیاز به اصلاح، پشتیبانی وضعیت `changes_requested` ثبت می‌کند؛ مشتری نسخه اصلاح‌شده را ارسال می‌کند ← اعلان سودو ♻️
- اپراتور سفارش را در Telegram Ads ثبت و نتیجه (تأیید/رد) را ثبت می‌کند
- در نهایت کمپین زمان‌بندی، اجرا و خاتمه می‌یابد

---

## تکنولوژی

- PHP 8.3+ و Laravel 13
- MariaDB/MySQL 8 (utf8mb4)
- Vite و Tailwind CSS 4
- فونت‌های Vazirmatn و Manrope
- مدیریت پروسس با PM2

---

## نصب و راه‌اندازی

### روش ۱: نصب خودکار روی سرور لینوکس (پیشنهادی)

```bash
git clone https://github.com/miladrajabi2002/TelegramAdsBot.git
cd TelegramAdsBot
sudo APP_DOMAIN=bot.example.com bash bin/install.sh
```

اسکریپت نصب به‌صورت خودکار و idempotent (قابل اجرای مکرر):

1. نصب پیش‌نیازهای OS در صورت نبود: nginx، PHP 8.3+، MariaDB/MySQL، Node.js، Composer، certbot و PM2
2. نصب افزونه‌های PHP مورد نیاز Laravel
3. `composer install` و `npm ci && npm run build`
4. ساخت `.env` با کلیدها و secretهای تصادفی + ساخت دیتابیس و کاربر MySQL
5. `php artisan migrate --seed` و `storage:link` و کش‌سازی و تنظیم دسترسی‌ها
6. ساخت کانفیگ nginx برای دامنه و دریافت گواهی SSL (در صورت اشاره DNS)
7. نصب و اجرای PM2 (`tgads-queue` و `tgads-sched`) + `pm2 save`
8. ثبت webhook تلگرام (در صورت وجود `TELEGRAM_BOT_TOKEN`)

می‌توانید مقادیر را موقع نصب override کنید:

```bash
sudo APP_DOMAIN=bot.example.com DB_NAME=x DB_USER=y DB_PASS=z \
     TELEGRAM_BOT_TOKEN=xxx TELEGRAM_SODO_ID=773280563 bash bin/install.sh
```

> **ایمیل غیرفعال است** (`MAIL_MAILER=log`)؛ نیازی به SMTP نیست.

### روش ۲: نصب دستی گام‌به‌گام

پیش‌نیازها: PHP 8.3+ (با افزونه‌های `cli, fpm, mysql, mbstring, xml, curl, zip, gd, bcmath, intl`), Composer، Node.js 20+ و npm، MySQL/MariaDB، PM2.

```bash
# ۱) دریافت سورس و نصب پکیج‌ها
git clone https://github.com/miladrajabi2002/TelegramAdsBot.git
cd TelegramAdsBot
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# ۲) فایل محیطی
cp .env.example .env
php artisan key:generate

# ۳) دیتابیس — در .env مقادیر DB_DATABASE/DB_USERNAME/DB_PASSWORD را تنظیم کنید
mysql -u root -e "CREATE DATABASE ads_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ۴) مهاجرت جداول + داده اولیه (ادمین، کاتالوگ، ...)
php artisan migrate --seed

# ۵) لینک storage و کش‌سازی
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache

# ۶) اجرای صف و زمانبند با PM2
pm2 start ecosystem.config.cjs
pm2 save

# ۷) ثبت webhook تلگرام (پس از تنظیم TELEGRAM_BOT_TOKEN در .env)
php artisan telegram:webhook:set
```

راهنمای کامل سرور، nginx، متغیرهای محیطی، آدرس‌ها، ورود ادمین و ثبت webhook/patch: [docs/SERVER_DEPLOYMENT.md](docs/SERVER_DEPLOYMENT.md)

### توسعه محلی

```bash
composer install
npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

بررسی سلامت:

```bash
php artisan test
php artisan route:list
php artisan migrate:status
```

### به‌روزرسانی پس از تغییر کد

```bash
sudo bash bin/update.sh   # git pull + composer/npm + migrate + کش + ریستارت PM2
```

---

## پیکربندی (متغیرهای مهم `.env`)

| متغیر | توضیح |
|---|---|
| `APP_URL` / `APP_DOMAIN` | دامنه شما (مثلاً `https://bot.example.com`) |
| `TELEGRAM_BOT_TOKEN` | توکن ربات از @BotFather (`/newbot` یا `/token`) |
| `TELEGRAM_BOT_USERNAME` | یوزرنیم ربات بدون `@` |
| `TELEGRAM_WEBHOOK_SECRET` | secret اعتبارسنجی webhook — خودکار در نصب |
| `TELEGRAM_SODO_ID` | **شناسه چت اکانت سودو (مالک)** — دریافت اعلان ثبت تبلیغ جدید / ارسال مجدد نسخه اصلاح‌شده. خالی یا `0` = غیرفعال |
| `ADMIN_PATH_PREFIX` | مسیر مخفی پنل ادمین (پیش‌فرض `jsfiopios5/admin`) |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | اکانت ادمین اولیه — خودکار در نصب |
| `ZARINPAY_ACCESS_TOKEN` | توکن درگاه ریالی ZarinPay/Zarinmee |
| `NOWPAYMENTS_API_KEY` / `_PUBLIC_KEY` / `_IPN_SECRET` | کلیدهای درگاه ارزی NowPayments |
| `KYC_HMAC_KEY` | کلید امضای مدارک KYC — خودکار در نصب |

ثبت webhook تلگرام: `php artisan telegram:webhook:set`
ثبت patch درگاه (IPN NowPayments و فعال‌سازی `NOWPAYMENTS_ENABLED=true`): [docs/SERVER_DEPLOYMENT.md](docs/SERVER_DEPLOYMENT.md) بخش ۷.

---

## اعلان‌های اکانت سودو (جدید)

با تنظیم `TELEGRAM_SODO_ID` در `.env`، پیام هشدار فوری برای اکانت مالک ارسال می‌شود:

- 🆕 **ثبت تبلیغ جدید**: هرگاه مشتری سفارش تبلیغ جدیدی ثبت کند (وضعیت «در انتظار پرداخت»)
- ♻️ **ارسال مجدد نسخه اصلاح‌شده**: هرگاه مشتری سفارش رد‌شده را اصلاح و برای بررسی مجدد بفرستد

هر پیام شامل شماره سفارش، مشخصات کاربر، عنوان و متن تبلیغ، نوع انتشار، شماره نسخه، بودجه، مبلغ کل، وضعیت فعلی و دکمه «مشاهده سفارش در پنل مدیریت» است. ارسال از طریق صف انجام می‌شود و در صورت تنظیم‌نبودن مقدار، بی‌صدا غیرفعال می‌ماند.

```env
TELEGRAM_SODO_ID=773280563
```

---

## آدرس‌ها و URLها (پس از نصب با دامنه `bot.example.com`)

| مورد | URL |
|---|---|
| Mini App (صفحه مشتری) | `https://bot.example.com/app` |
| پنل ادمین | `https://bot.example.com/jsfiopios5/admin/login` (طبق `ADMIN_PATH_PREFIX`) |
| Webhook تلگرام | `https://bot.example.com/webhooks/telegram` |
| IPN درگاه NowPayments | `https://bot.example.com/webhooks/nowpayments` |
| Callback درگاه ZarinPay | `https://bot.example.com/payments/zarinpay/callback` |
| Health check | `https://bot.example.com/healthz` |

---

## ورود به پنل ادمین

آدرس پنل عمداً مخفی است و مطابق `ADMIN_PATH_PREFIX` در `.env` قابل تغییر است (پیش‌فرض: `/jsfiopios5/admin/login`). با `ADMIN_EMAIL` و `ADMIN_PASSWORD` (که هنگام نصب چاپ و در `.env` ذخیره شده‌اند) وارد شوید. نقش پیش‌فرض `super_admin` با دسترسی کامل است.

---

## پروسس‌های PM2

| نام | کار |
|---|---|
| `tgads-queue` | کارگر صف دیتابیس (بلاست/پیام تلگرام/اعلان سودو) |
| `tgads-sched` | زمانبند هر دقیقه |

```bash
pm2 status
pm2 logs tgads-queue
sudo bash bin/update.sh   # به‌روزرسانی پس از تغییر کد
```

> بعد از هر تغییر کد، ریستارت کارگر صف ضروری است چون کد را در حافظه نگه می‌دارد: `pm2 restart tgads-queue tgads-sched`

---

## مستندات

- [راه‌اندازی روی سرور + PM2](docs/SERVER_DEPLOYMENT.md) ← نصب یک‌کلیک، nginx، env، ورود ادمین، ثبت patch/webhook
- [معماری و دامنه](docs/ARCHITECTURE.md)
- [نصب روی cPanel](docs/CPANEL_DEPLOYMENT.md)
- [راه‌اندازی BotFather، Mini App و webhook](docs/TELEGRAM_SETUP.md)
- [ZarinPay، NowPayments و جریان مالی](docs/PAYMENTS.md)
- [راهنمای اپراتور نسخه اول](docs/OPERATOR_V1.md)
- [امنیت، KYC و نگهداری داده](docs/SECURITY_KYC.md)
- [شرایط سرویس (fa/en)](docs/legal/TERMS.fa.md) · [قوانین محتوای تبلیغ](docs/legal/ADS_POLICY.fa.md)

منابع رسمی: [Mini Apps](https://core.telegram.org/bots/webapps) · [Bot API](https://core.telegram.org/bots/api) · [Telegram Ads](https://ads.telegram.org/getting-started) · [Sponsored Messages API](https://core.telegram.org/api/sponsored-messages).

---

## قاعده انتشار

پیش از فعال‌کردن پرداخت یا پذیرش مشتری واقعی، موارد `TODO-LEGAL` و `TODO-OWNER` در مستندات باید تعیین تکلیف شوند و مدل کسب‌وکار، پرداخت داخل Mini App، KYC و سیاست بازپرداخت توسط مشاور حقوقی بررسی شود. پرداخت مستقیم داخل Mini App می‌تواند با قواعد Telegram Stars در تعارض باشد؛ تا تأیید حقوقی فعال نشود.

## گزارش مشکل امنیتی

آسیب‌پذیری را عمومی ثبت نکنید. نشانی تماس امنیتی را پس از تعیین مالک سرویس جایگزین و یک مسیر محرمانه گزارش‌دهی ایجاد کنید.
