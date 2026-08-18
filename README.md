# Telegram Ads Service Platform

هسته یک پلتفرم دوزبانه برای دریافت سفارش تبلیغات تلگرام، پرداخت، احراز هویت ریالی، عملیات اپراتوری و گزارش‌دهی است. رابط مشتری به‌صورت Telegram Mini App و پنل ادمین به‌صورت وب واکنش‌گرا طراحی می‌شود.

> وضعیت فعلی: یک V1 اپراتورمحور قابل اجرا شامل Mini App، پنل ادمین، جریان‌های اصلی دامنه و اتصال‌های اولیه ساخته شده است؛ بااین‌حال Production-ready نیست. موارد TODO-LEGAL/TODO-OWNER، feature flag پرداخت مستقیم، reconciliation مالی، سخت‌سازی webhook/KYC و آزمون end-to-end درگاه‌ها باید پیش از پذیرش مشتری واقعی تکمیل شوند.

## مرز مهم سرویس

- این پروژه مستقل و غیررسمی است و متعلق به Telegram، نماینده Telegram یا شریک رسمی Telegram Ads نیست.
- در مستندات عمومی رسمی که هنگام نگارش بررسی شده‌اند، API عمومی مستندی برای ایجاد و مدیریت کمپین تبلیغ‌دهنده در Telegram Ads وجود ندارد. APIهای Sponsored Messages برای دریافت و نمایش تبلیغ در کلاینت تلگرام هستند، نه ساخت کمپین تبلیغاتی.
- نسخه اول عمداً اپراتورمحور است: ثبت کمپین در پنل رسمی Telegram Ads و انتقال وضعیت و آمار به سامانه توسط اپراتور انجام می‌شود.
- ورود خودکار به پنل Telegram Ads، نگهداری session یا cookie اپراتور و scraping پنل جزو طراحی نیست.
- این محصول یک خدمت تبلیغاتی با تحویل دیجیتال ارائه می‌کند و ممکن است مشمول قواعد خدمات دیجیتال باشد. Telegram اعلام کرده فروش کالا و خدمات دیجیتال داخل Bot و Mini App باید با Telegram Stars انجام شود. نمایش پرداخت مستقیم ZarinPay یا NOWPayments داخل Mini App می‌تواند ریسک جدی انطباق و حذف دسترسی در موبایل ایجاد کند. مسیر پرداخت مستقیم تا زمان بررسی حقوقی، قراردادی و Policy نباید در محیط عمومی فعال شود.

منابع رسمی:

- [Telegram Mini Apps](https://core.telegram.org/bots/webapps)
- [Payments for Digital Goods and Services](https://core.telegram.org/bots/payments-stars)
- [Telegram Bot API](https://core.telegram.org/bots/api)
- [Telegram Ads: Getting Started](https://ads.telegram.org/getting-started)
- [Telegram Ads Policies](https://ads.telegram.org/guidelines)
- [Telegram Sponsored Messages API](https://core.telegram.org/api/sponsored-messages)

## دامنه نسخه اول

- ورود کاربر با داده امضاشده Telegram Mini App
- فارسی راست‌به‌چپ و انگلیسی چپ‌به‌راست
- رابط روشن؛ Dark Theme در دامنه محصول فعلی نیست
- احراز هویت اجباری پیش از واریز ریالی
- بررسی دستی شماره همراه، کارت ملی، تصویر شخص همراه کارت ملی، نام صاحب حساب و کارت بانکی
- ثبت سفارش و نسخه‌های اصلاح‌شده کمپین
- پرداخت مستقیم سفارش یا افزایش کیف پول، بدون اجبار به شارژ قبلی
- دفترکل دوطرفه، رزرو وجه سفارش و ثبت idempotent تراکنش‌ها
- بررسی محتوا توسط پشتیبانی
- صف اپراتور برای ثبت دستی در Telegram Ads
- ثبت دستی وضعیت و snapshotهای تجمعی آمار
- دسته‌بندی و حداکثر ۳۰ کانال پیشنهادی در هر دسته
- پنل ادمین واکنش‌گرا، گزارش، تیکت، audit log و ارسال همگانی صف‌بندی‌شده

## فناوری

- PHP 8.3+
- Laravel 13
- MySQL 8
- Vite 8 و Tailwind CSS 4
- فونت‌های Vazirmatn و Manrope
- Database queue برای سازگاری با cPanel

پول ریالی در دیتابیس با واحد IRR و عدد صحیح ذخیره می‌شود. نمایش تومان فقط تبدیل نمایشی IRR تقسیم بر ۱۰ است. نرخ کارمزد هر سفارش در لحظه قیمت‌گذاری snapshot می‌شود؛ مقدار پیش‌فرض migration برابر ۱۵۰۰ basis point، معادل ۱۵٪، است.

## شروع توسعه محلی

پیش‌نیازها: PHP 8.3، Composer، Node.js سازگار با Vite 8 و MySQL 8.

    composer install
    npm ci
    php -r "file_exists('.env') || copy('.env.example', '.env');"
    php artisan key:generate
    php artisan migrate --seed
    npm run build
    php artisan serve

اگر .env.example هنوز در شاخه شما وجود ندارد، متغیرها را از بخش Environment در [راهنمای نصب cPanel](docs/CPANEL_DEPLOYMENT.md) به‌صورت دستی بسازید. هیچ secret واقعی نباید commit شود.

برای بررسی وضعیت فعلی:

    php artisan test
    php artisan route:list
    php artisan migrate:status

## مستندات

- [فهرست مستندات](docs/README.md)
- [معماری و دامنه](docs/ARCHITECTURE.md)
- [نصب روی cPanel](docs/CPANEL_DEPLOYMENT.md)
- [راه‌اندازی BotFather، Mini App و webhook](docs/TELEGRAM_SETUP.md)
- [ZarinPay، NOWPayments و جریان مالی](docs/PAYMENTS.md)
- [راهنمای اپراتور نسخه اول](docs/OPERATOR_V1.md)
- [امنیت، KYC و نگهداری داده](docs/SECURITY_KYC.md)
- [شرایط سرویس فارسی](docs/legal/TERMS.fa.md)
- [Terms of Service](docs/legal/TERMS.en.md)
- [قوانین محتوای تبلیغ فارسی](docs/legal/ADS_POLICY.fa.md)
- [Advertising Content Policy](docs/legal/ADS_POLICY.en.md)

## قاعده انتشار

پیش از فعال‌کردن پرداخت یا پذیرش مشتری واقعی، تمام موارد PLACEHOLDER، TODO-LEGAL و TODO-OWNER در docs باید تعیین تکلیف شوند. همچنین باید یک متخصص حقوقی واجد صلاحیت، مدل کسب‌وکار، پرداخت داخل Mini App، KYC، نگهداری مدارک و سیاست بازپرداخت را برای حوزه قضایی فعالیت بررسی کند.

## گزارش مشکل امنیتی

آسیب‌پذیری را عمومی ثبت نکنید. نشانی SECURITY_CONTACT_EMAIL را پس از تعیین مالک سرویس جایگزین و یک مسیر محرمانه گزارش‌دهی ایجاد کنید.
