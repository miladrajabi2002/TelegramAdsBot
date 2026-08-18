# راه‌اندازی BotFather، Mini App و webhook

## پیش‌شرط

- دامنه عمومی با HTTPS معتبر
- route فعال Mini App
- route فعال Telegram webhook
- توکن Bot در secret storage سرور
- متن شرایط، حریم خصوصی و پشتیبانی

این ربات و Mini App سرویس مستقل و غیررسمی هستند. About، Description و رابط نباید عبارت‌هایی مانند official، نماینده رسمی یا شریک Telegram را بدون مدرک قراردادی استفاده کنند.

## ۱. ساخت Bot

در گفت‌وگو با @BotFather:

1. دستور /newbot را اجرا کنید.
2. نام نمایشی و username منحصربه‌فرد انتخاب کنید.
3. token را فقط یک‌بار در password manager ثبت کنید.
4. با /setdescription توضیح روشن سرویس مستقل را بنویسید.
5. با /setabouttext متن کوتاه و دوزبانه مناسب قرار دهید.
6. با /setuserpic لوگوی اختصاصی سرویس را بارگذاری کنید.
7. commandها را تنظیم کنید.

commandهای پیشنهادی:

    start - Open the service / شروع
    help - Help / راهنما
    support - Contact support / پشتیبانی
    terms - Terms of service / شرایط
    privacy - Privacy policy / حریم خصوصی
    paysupport - Payment support / پشتیبانی پرداخت

handler فعلی /start، /help، /terms، /privacy، /support و /paysupport را پاسخ می‌دهد. setWebhook فعلی فقط update نوع message را درخواست می‌کند؛ در صورت افزودن callback_query یا پرداخت Stars، ابتدا handler، idempotency و آزمون‌های متناظر را پیاده و سپس allowed_updates را گسترش دهید.

مالک حساب Bot باید Telegram Two-Step Verification را فعال کند. token را در پیام‌رسان، فایل frontend یا مخزن Git قرار ندهید. در صورت افشا، آن را فوراً در BotFather revoke و secret سرور را تعویض کنید.

## ۲. فعال‌کردن Main Mini App

مسیر فعلی BotFather:

    /mybots
      -> انتخاب Bot
      -> Bot Settings
      -> Configure Mini App
      -> Enable Mini App

URL پیشنهادی:

    https://{APP_DOMAIN}/app

سپس Menu Button را از Bot Settings یا /setmenubutton به همان URL متصل کنید. برای Main Mini App می‌توان preview چندزبانه نیز بارگذاری کرد.

لینک شروع:

    https://t.me/{BOT_USERNAME}?startapp

اگر start parameter استفاده می‌شود، آن را ورودی غیرقابل اعتماد بدانید و سمت سرور validation و allowlist کنید.

منبع رسمی: [Telegram Mini Apps](https://core.telegram.org/bots/webapps)

## ۳. احراز هویت Mini App

frontend داده خام Telegram.WebApp.initData را به backend می‌فرستد. Telegram.WebApp.initDataUnsafe منبع هویت قابل اعتماد نیست.

backend باید:

1. رشته initData را بدون تغییر دریافت کند.
2. hash امضای Telegram را طبق الگوریتم رسمی و با Bot token بررسی کند.
3. مقایسه hash را constant-time انجام دهد.
4. auth_date را کنترل و درخواست قدیمی‌تر از بازه کوتاه مجاز را رد کند.
5. telegram user ID را تنها بعد از اعتبارسنجی برای ایجاد session داخلی استفاده کند.
6. session را regenerate و CSRF را برای درخواست‌های مرورگری حفظ کند.
7. query_id و start_param را مجوز انجام عملیات حساس تلقی نکند.

بازه پیشنهادی initData در Production پنج دقیقه است؛ مقدار نهایی با توجه به UX و ارزیابی امنیتی تعیین شود.

آزمون‌های اجباری:

- hash نادرست
- initData منقضی
- تغییر user ID بعد از امضا
- replay
- locale نامعتبر
- درخواست مستقیم مرورگر خارج Telegram

## ۴. route وب‌هوک

URL پیشنهادی:

    https://{APP_DOMAIN}/webhooks/telegram

الزامات handler:

- فقط POST
- بررسی header برابر X-Telegram-Bot-Api-Secret-Token
- محدودیت اندازه body
- parse امن JSON
- ثبت idempotent update_id
- پاسخ سریع 2xx
- انتقال کار سنگین به queue
- عدم ثبت token، متن حساس یا payload کامل در log عمومی

نباید Bot token در path وب‌هوک قرار گیرد. secret header جایگزین احراز هویت مسیر است.

## ۵. ثبت webhook

Telegram فقط URL دارای HTTPS را می‌پذیرد. درخواست نمونه:

    curl --request POST "https://api.telegram.org/bot{BOT_TOKEN}/setWebhook" \
      --data-urlencode "url=https://{APP_DOMAIN}/webhooks/telegram" \
      --data-urlencode "secret_token={TELEGRAM_WEBHOOK_SECRET}" \
      --data-urlencode 'allowed_updates=["message"]'

مقدار allowed_updates را با handlerهای واقعی هماهنگ کنید. اگر Stars پیاده نشده است، pre_checkout_query را بی‌دلیل فعال نکنید.

از drop_pending_updates فقط هنگام launch یا incident و پس از بررسی اثر از‌دست‌رفتن updateها استفاده کنید.

بررسی وضعیت:

    curl "https://api.telegram.org/bot{BOT_TOKEN}/getWebhookInfo"

موارد قابل بررسی:

- url صحیح
- pending_update_count
- last_error_message
- last_error_date
- certificate معتبر

برای حذف webhook:

    curl --request POST "https://api.telegram.org/bot{BOT_TOKEN}/deleteWebhook"

منبع رسمی: [Telegram Bot API: setWebhook](https://core.telegram.org/bots/api#setwebhook)

## ۶. webhook و cPanel

- request باید پیش از timeout هاست پاسخ دهد.
- update ابتدا idempotent ذخیره و سپس Job ساخته شود.
- ارسال همگانی در webhook اجرا نشود.
- اگر queue متوقف است، health check و alert لازم است.
- WAF یا ModSecurity نباید POST تلگرام را بی‌دلیل مسدود کند.
- برای webhook secret فقط کاراکترهای A-Z، a-z، 0-9، underscore و dash استفاده شود.

## ۷. اعلان‌های سفارش

پیام‌های Bot باید فقط رویدادهای لازم را اعلام کنند:

- دریافت پرداخت
- نیاز به اصلاح KYC
- نیاز به اصلاح آگهی
- ثبت در Telegram Ads
- تأیید یا رد تلگرام
- شروع، توقف و پایان کمپین
- پاسخ تیکت

داده حساس در پیام Telegram ارسال نشود. شماره کارت فقط masked، کد ملی هرگز، و تصویر KYC هرگز در chat نمایش داده نشود.

## ۸. پرداخت داخل Mini App

این محصول یک خدمت تبلیغاتی با تحویل دیجیتال ارائه می‌کند و ممکن است مشمول قواعد خدمات دیجیتال باشد. راهنمای رسمی Telegram می‌گوید فروش خدمات دیجیتال داخل Bot و Mini App باید منحصراً با Telegram Stars انجام شود، حتی اگر کسب‌وکار وب‌سایت یا provider دیگری نیز داشته باشد.

بنابراین:

- اتصال فنی ZarinPay یا NOWPayments به backend به معنی مجازبودن نمایش آن داخل Mini App نیست.
- پرداخت مستقیم ریالی یا رمزارزی داخل Telegram تا قبل از بررسی حقوقی و Policy فعال نشود.
- گزینه‌های قابل بررسی شامل Stars داخل Telegram و checkout مستقل خارج از Telegram هستند.
- تصمیم باید با حوزه قضایی، قرارداد merchant، App Store و Google Play نیز تطبیق داده شود.

منبع رسمی: [Payments for Digital Goods and Services](https://core.telegram.org/bots/payments-stars)

## ۹. چک‌لیست پذیرش

- Bot token در frontend دیده نمی‌شود.
- initData سمت سرور و با محدودیت زمان تأیید می‌شود.
- webhook secret اجباری است.
- update تکراری دوباره اجرا نمی‌شود.
- Main Mini App و Menu Button به HTTPS صحیح اشاره می‌کنند.
- /terms، /privacy، /support و /paysupport پاسخ معتبر دارند.
- هیچ ادعای وابستگی رسمی به Telegram وجود ندارد.
- پرداخت مستقیم تا تأیید Policy غیرفعال یا feature-flagged است.
