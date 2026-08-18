# Telegram Ads Bot

پلتفرم دوزبانه برای دریافت سفارش تبلیغات تلگرام، احراز هویت ریالی (KYC)، کیف پول داخلی، پرداخت (ریالی/ZarinPay و ارزی/NowPayments)، عملیات اپراتور و گزارش‌دهی. رابط مشتری به‌صورت **Telegram Mini App** و پنل ادمین به‌صورت وب واکنش‌گرا است.

> این سرویس مستقل و غیررسمی است و متعلق به Telegram یا نماینده/شریک رسمی Telegram Ads نیست. نسخه اول اپراتورمحور است: ثبت کمپین در پنل رسمی Telegram Ads و انتقال وضعیت و آمار توسط اپراتور انجام می‌شود.

---

## امکانات نسخه اول

- ورود کاربر با داده امضاشده Telegram Mini App (`initData`).
- رابط دوزبانه فارسی راست‌به‌چپ / انگلیسی چپ‌به‌راست.
- احراز هویت اجباری پیش از واریز ریالی: شماره همراه، کارت ملی، تصویر شخص با کارت ملی، نام صاحب حساب و کارت بانکی.
- ثبت سفارش و نسخه‌های اصلاح‌شده کمپین.
- کیف پول داخلی و دفترکل دوطرفه با رزرو وجه سفارش و تراکنش idempotent.
- پرداخت مستقیم سفارش یا افزایش کیف پول (ZarinPay ریالی / NowPayments ارزی).
- بررسی محتوا توسط پشتیبانی و صف اپراتور برای ثبت دستی در Telegram Ads.
- ثبت دستی وضعیت و snapshotهای آمار تجمعی.
- پنل ادمین: داشبورد، سفارش، KYC، کاربران، تراکنش‌ها، کاتالوگ کانال، گزارش، بلاست همگانی، تیکت، audit log و تنظیمات.
- صف دیتابیس (سازگار با هاست اشتراکی) و زمانبند.

## تکنولوژی

- PHP 8.3+ و Laravel 13
- MariaDB/MySQL 8 (utf8mb4)
- Vite و Tailwind CSS 4
- فونت‌های Vazirmatn و Manrope
- مدیریت پروسس با PM2

مبلغ ریالی با واحد IRR (صحیح) ذخیره می‌شود؛ نمایش تومان فقط تبدیل نمایشی (`/10`) است. کارمزد پیش‌فرض ۱۵۰۰ basis point (۱۵٪) است.

---

## نصب سریع روی سرور (یک‌کلیک + PM2)

```bash
git clone https://github.com/miladrajabi2002/TelegramAdsBot.git
cd TelegramAdsBot
sudo APP_DOMAIN=bot.example.com bash bin/install.sh
```

اسکریپت نصب به‌صورت خودکار: بررسی/نصب پسوندهای PHP، `composer install`، `npm ci && npm run build`، ساخت `.env` با secretهای تصادفی، ساخت دیتابیس و کاربر، `migrate --seed`، کش‌سازی، تنظیم دسترسی، نصب و اجرای PM2 (`tgads-queue` و `tgads-sched`) و `pm2 save`/`startup`.

> **ایمیل غیرفعال است** (`MAIL_MAILER=log`)؛ نیازی به SMTP نیست.

راهنمای کامل سرور، nginx، متغیرهای محیطی، آدرس‌ها، ورود ادمین و ثبت webhook/patch: [docs/SERVER_DEPLOYMENT.md](docs/SERVER_DEPLOYMENT.md).

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
php artisan test          # 42 تست
php artisan route:list
php artisan migrate:status
```

---

## آدرس‌ها و URLها (پس از نصب با دامنه `bot.example.com`)

| مورد | URL |
|---|---|
| Mini App (صفحه مشتری) | `https://bot.example.com/app` |
| پنل ادمین | `https://bot.example.com/admin/login` |
| Webhook تلگرام | `https://bot.example.com/webhooks/telegram` |
| IPN درگاه NowPayments | `https://bot.example.com/webhooks/nowpayments` |
| Callback درگاه ZarinPay | `https://bot.example.com/payments/zarinpay/callback` |
| Health check | `https://bot.example.com/healthz` |

---

## ورود به پنل ادمین

به `/admin/login` بروید و با `ADMIN_EMAIL` و `ADMIN_PASSWORD` (که هنگام نصب چاپ و در `.env` ذخیره شده‌اند) وارد شوید. نقش پیش‌فرض `super_admin` با دسترسی کامل است.

---

## متغیرهای مهم محیطی

| متغیر | از کجا |
|---|---|
| `APP_URL` / `APP_DOMAIN` | دامنه شما |
| `TELEGRAM_BOT_TOKEN` | @BotFather (`/newbot` یا `/token`) |
| `TELEGRAM_BOT_USERNAME` | @BotFather (بدون `@`) |
| `TELEGRAM_WEBHOOK_SECRET` | خودکار در نصب |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | خودکار در نصب |
| `NOWPAYMENTS_API_KEY` / `_PUBLIC_KEY` / `_IPN_SECRET` | پنل NowPayments |
| `ZARINPAY_ACCESS_TOKEN` | پنل ZarinPay/Zarinmee |
| `KYC_HMAC_KEY` | خودکار در نصب |

ثبت webhook تلگرام: `php artisan telegram:webhook:set`
ثبت patch درگاه (IPN NowPayments و فعال‌سازی `NOWPAYMENTS_ENABLED=true`): به [docs/SERVER_DEPLOYMENT.md](docs/SERVER_DEPLOYMENT.md) بخش ۷ رجوع کنید.

---

## پروسس‌های PM2

| نام | کار |
|---|---|
| `tgads-queue` | کارگر صف دیتابیس (بلاست/پیام تلگرام) |
| `tgads-sched` | زمانبند هر دقیقه |

```bash
pm2 status
pm2 logs tgads-queue
sudo bash bin/update.sh   # به‌روزرسانی پس از تغییر کد
```

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
