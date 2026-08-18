# فهرست مستندات

این پوشه مرجع معماری، استقرار، عملیات و سیاست‌های پروژه است. مستندات میان «طرح نسخه اول» و «قابلیت پیاده‌شده» تفاوت می‌گذارند؛ برای تشخیص قابلیت قابل اجرا همیشه routeها، Jobها و تست‌های همان نسخه را نیز بررسی کنید.

## راهنمای فنی

| سند | مخاطب | موضوع |
|---|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | توسعه‌دهنده، مدیر محصول | دامنه، ماژول‌ها، وضعیت‌ها، داده و محدودیت V1 |
| [CPANEL_DEPLOYMENT.md](CPANEL_DEPLOYMENT.md) | DevOps، مالک هاست | PHP 8.3، MySQL، build، cron، queue و انتشار |
| [TELEGRAM_SETUP.md](TELEGRAM_SETUP.md) | مالک Bot، توسعه‌دهنده | BotFather، Main Mini App، webhook و اعتبارسنجی initData |
| [PAYMENTS.md](PAYMENTS.md) | توسعه‌دهنده، مالی، حقوقی | ZarinPay، NOWPayments، callback/IPN، دفترکل و ریسک انطباق |
| [OPERATOR_V1.md](OPERATOR_V1.md) | اپراتور و سرپرست | ثبت دستی کمپین و همگام‌سازی snapshot |
| [SECURITY_KYC.md](SECURITY_KYC.md) | امنیت، اپراتور KYC، حقوقی | داده حساس، کنترل دسترسی، بررسی کارت و retention |

## متن‌های قابل انتشار پس از بررسی حقوقی

| سند | زبان |
|---|---|
| [legal/TERMS.fa.md](legal/TERMS.fa.md) | شرایط سرویس فارسی |
| [legal/TERMS.en.md](legal/TERMS.en.md) | English Terms of Service |
| [legal/ADS_POLICY.fa.md](legal/ADS_POLICY.fa.md) | قوانین محتوای تبلیغ فارسی |
| [legal/ADS_POLICY.en.md](legal/ADS_POLICY.en.md) | English Advertising Content Policy |

این چهار سند template هستند و تا زمان جایگزینی موارد TODO-LEGAL و TODO-OWNER متن حقوقی نهایی محسوب نمی‌شوند.

## URLهای استاندارد پیشنهادی

دامنه نمونه را با دامنه واقعی HTTPS جایگزین کنید:

| کاربرد | URL |
|---|---|
| Mini App | https://{APP_DOMAIN}/app |
| پنل ادمین | https://{APP_DOMAIN}/admin |
| Telegram webhook | https://{APP_DOMAIN}/webhooks/telegram |
| ZarinPay callback | https://{APP_DOMAIN}/payments/zarinpay/callback |
| NOWPayments IPN | https://{APP_DOMAIN}/webhooks/nowpayments |
| شرایط سرویس | https://{APP_DOMAIN}/legal/terms |
| حریم خصوصی | https://{APP_DOMAIN}/legal/privacy |
| پشتیبانی داخل Mini App | https://{APP_DOMAIN}/app/support |
| پشتیبانی پرداخت در Bot | فرمان /paysupport |

مسیر /app/support به نشست معتبر Mini App نیاز دارد. URLها تا زمانی که در php artisan route:list دیده نشده‌اند فعال محسوب نمی‌شوند و پاسخ فرمان‌های Bot نیز باید جداگانه با webhook واقعی آزمایش شود.
