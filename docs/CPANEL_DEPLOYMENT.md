# نصب و انتشار روی cPanel

این راهنما برای Laravel 13، PHP 8.3، MySQL 8 و هاست اشتراکی دارای Terminal یا SSH نوشته شده است. نام مسیرها نمونه است؛ مسیر واقعی home و PHP CLI را از هاست بگیرید.

> هشدار وضعیت: پیش از استقرار، php artisan route:list را بررسی کنید. اگر routeهای Mini App، پنل ادمین، Telegram webhook و callbackهای پرداخت وجود ندارند، استقرار فقط یک skeleton خواهد بود و نباید به مشتری واقعی ارائه شود.

## ۱. پیش‌نیاز هاست

Laravel 13 حداقل PHP 8.3 می‌خواهد. افزونه‌های لازم یا توصیه‌شده:

- Ctype
- cURL
- DOM
- Fileinfo
- Filter
- Hash
- Mbstring
- OpenSSL
- PCRE
- PDO و pdo_mysql
- Session
- Tokenizer
- XML
- Intl الزامی برای نمایش تقویم شمسی و محاسبه درست بازه «ماه جاری» فارسی
- GD یا Imagick فقط اگر پردازش تصویر KYC در کد فعال شود

همچنین لازم است:

- SSL معتبر و HTTPS اجباری
- MySQL 8 با utf8mb4
- Composer 2
- Cron Jobs هر دقیقه
- امکان تغییر document root دامنه یا subdomain به پوشه public
- دسترسی نوشتن PHP به storage و bootstrap/cache
- فضای private خارج از public_html برای مدارک KYC

بررسی اولیه:

    php -v
    php -m
    composer --version
    php -r "echo PHP_BINARY, PHP_EOL;"

در بعضی هاست‌ها مسیر CLI نمونه‌ای مانند زیر است:

    /opt/cpanel/ea-php83/root/usr/bin/php

همیشه همان binary نسخه ۸.۳ را در Cron استفاده کنید؛ نسخه PHP وب و CLI ممکن است متفاوت باشند.

منبع نیازمندی رسمی: [Laravel Deployment](https://laravel.com/docs/13.x/deployment)

## ۲. ساخت دامنه و پایگاه داده

1. در cPanel یک subdomain مانند app.example.com ایجاد کنید.
2. SSL را فعال کنید و redirect به HTTPS را اجباری کنید.
3. یک MySQL database و user با رمز تصادفی طولانی بسازید.
4. تمام مجوزهای لازم را فقط روی همان database به همان user بدهید.
5. collation پیشنهادی utf8mb4_unicode_ci است.

ساختار پیشنهادی:

    /home/{CPANEL_USER}/apps/telegram-ads/
        app/
        bootstrap/
        config/
        database/
        public/
        resources/
        routes/
        storage/
        vendor/

Document root دامنه باید دقیقاً این مسیر باشد:

    /home/{CPANEL_USER}/apps/telegram-ads/public

کل پروژه را داخل public_html قرار ندهید. فایل .env، storage، vendor و مدارک KYC نباید از وب قابل دسترس باشند. اگر هاست اجازه تغییر document root نمی‌دهد، پیش از استفاده عمومی پلن هاست را تغییر دهید؛ کپی‌کردن پراکنده فایل‌های public و تغییر دستی index.php راهکار پایدار و امنی نیست.

## ۳. انتقال و build

روش ترجیحی، Git deployment یا بارگذاری archive نسخه release است. پوشه‌های توسعه، فایل‌های secret و database محلی را منتشر نکنید.

اگر Node.js روی cPanel موجود است:

    cd /home/{CPANEL_USER}/apps/telegram-ads
    npm ci
    npm run build

اگر Node.js موجود نیست، npm ci و npm run build را در محیط CI یا سیستم توسعه اجرا کنید و پوشه public/build تولیدشده را همراه release انتقال دهید.

نصب وابستگی PHP:

    cd /home/{CPANEL_USER}/apps/telegram-ads
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

اگر Composer با PHP اشتباه اجرا می‌شود:

    /opt/cpanel/ea-php83/root/usr/bin/php /path/to/composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction

## ۴. Environment

فایل .env فقط روی سرور ساخته می‌شود و نباید commit شود. متغیرهای زیر قرارداد پیشنهادی‌اند؛ wiring هر متغیر را با config همان نسخه تطبیق دهید.

    APP_NAME="Telegram Ads Service"
    APP_ENV=production
    APP_KEY=
    APP_DEBUG=false
    APP_URL=https://{APP_DOMAIN}
    APP_LOCALE=fa
    APP_FALLBACK_LOCALE=en

    LOG_CHANNEL=stack
    LOG_LEVEL=warning

    DB_CONNECTION=mysql
    DB_HOST=localhost
    DB_PORT=3306
    DB_DATABASE={CPANEL_DATABASE}
    DB_USERNAME={CPANEL_DATABASE_USER}
    DB_PASSWORD={LONG_RANDOM_PASSWORD}
    DB_CHARSET=utf8mb4
    DB_COLLATION=utf8mb4_unicode_ci

    SESSION_DRIVER=database
    CACHE_STORE=database
    QUEUE_CONNECTION=database
    QUEUE_FAILED_DRIVER=database-uuids

    TELEGRAM_BOT_TOKEN={SECRET}
    TELEGRAM_BOT_USERNAME={BOT_USERNAME_WITHOUT_AT}
    TELEGRAM_WEBHOOK_SECRET={RANDOM_AZ09_UNDERSCORE_DASH}
    TELEGRAM_INIT_DATA_TTL=300

    ADS_PLATFORM_BRAND="Ads Platform"
    ADS_PLATFORM_CHANNEL_USERNAME=
    ADS_PLATFORM_SUPPORT_USERNAME=
    ADS_PLATFORM_MARKUP_BPS=1500
    ADS_PLATFORM_MINIMUM_ORDER_IRR=1000000
    ADS_PLATFORM_MIN_TARGET_MEMBERS=1000
    ADS_PLATFORM_MAX_CHANNELS_PER_CATEGORY=30
    ADS_PLATFORM_TIMEZONE=Asia/Tehran

    ZARINPAY_ACCESS_TOKEN={SECRET}
    ZARINPAY_BASE_URL=https://zarinmee.ir/api
    ZARINPAY_ENABLED=false
    ZARINPAY_MOCK=false
    ZARINPAY_TIMEOUT=15
    ZARINPAY_PAYMENT_HOSTS=zarinmee.ir

    NOWPAYMENTS_API_KEY={SECRET}
    NOWPAYMENTS_PUBLIC_KEY=
    NOWPAYMENTS_IPN_SECRET={SECRET}
    NOWPAYMENTS_BASE_URL=https://api.nowpayments.io/v1
    NOWPAYMENTS_ENABLED=false
    NOWPAYMENTS_INVOICE_HOSTS=nowpayments.io

    KYC_RETENTION_DAYS={TODO_LEGAL}
    KYC_HMAC_KEY={INDEPENDENT_RANDOM_SECRET}
    USD_TO_IRR={OWNER_APPROVED_RATE}
    GRAM_TO_USD={OWNER_APPROVED_RATE}

    ADMIN_NAME="Platform Owner"
    ADMIN_EMAIL={OWNER_EMAIL}
    ADMIN_PASSWORD={LONG_RANDOM_PASSWORD}

نکات:

- APP_KEY را ننویسید؛ با php artisan key:generate تولید کنید.
- callback و IPN از APP_URL و routeهای برنامه ساخته می‌شوند؛ متغیرهای ZARINPAY_CALLBACK_URL و NOWPAYMENTS_IPN_URL در نسخه فعلی خوانده نمی‌شوند.
- ZARINPAY_STORE_ID و TELEGRAM_MINI_APP_URL نیز در config فعلی wiring نشده‌اند؛ افزودن آن‌ها به .env به‌تنهایی اثری ندارد.
- فایل‌های KYC روی disk خصوصی `kyc` نگهداری می‌شوند. `KYC_HMAC_KEY` را یک secret تصادفی مستقل و ثابت قرار دهید؛ تغییر یا گم‌شدن آن، تشخیص هویت/کارت تکراری را برای داده‌های قبلی مختل می‌کند. `KYC_RETENTION_DAYS` نیز باید پیش از انتشار به تصویب مسئول حقوقی برسد.
- ZARINPAY_ENABLED و NOWPAYMENTS_ENABLED را فقط پس از تکمیل تست end-to-end و بررسی حقوقی/Policy همان provider برابر true کنید؛ مقدار پیش‌فرض هر دو باید false بماند.
- config/app.php فعلی timezone را روی UTC ثابت نگه می‌دارد. تاریخ شمسی و Asia/Tehran باید در لایه نمایش اعمال شوند.
- اگر config هنوز این متغیرهای provider را نمی‌خواند، صرف قراردادن آن‌ها در .env اتصال را فعال نمی‌کند.
- secretها را در Ticket، screenshot، log یا JavaScript قرار ندهید.

تولید APP_KEY:

    php artisan key:generate

پس از تغییر .env:

    php artisan optimize:clear

config cache را بعد از migration و seed بسازید. DatabaseSeeder فعلی ADMIN_* را مستقیماً از environment می‌خواند؛ seed پس از config:cache ممکن است روی بعضی هاست‌ها این متغیرها را نبیند.

## ۵. Migration و cache

پیش از migration از database نسخه پشتیبان بگیرید.

    php artisan migrate --seed --force
    php artisan migrate:status
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

--seed رکورد ادمین، قیمت‌گذاری، تنظیمات پایه، دسته‌ها و نسخه‌های اولیه policy را می‌سازد. نسخه‌های policy سیدشده متن عملیاتی موقت‌اند؛ قبل از دسترسی مشتری باید با متن حقوقی مصوب جایگزین شوند. در Production بدون ADMIN_PASSWORD هیچ ادمینی ساخته نمی‌شود.

اگر routeها closure داشته باشند، route:cache ممکن است شکست بخورد؛ خطا را رفع کنید و آن را نادیده نگیرید.

storage و bootstrap/cache باید برای PHP قابل نوشتن باشند. از permission برابر 777 استفاده نکنید. مقدار دقیق 750 یا 770 به owner و group هاست بستگی دارد.

برای مدارک KYC دستور storage:link را اجرا نکنید. KYC باید روی disk خصوصی و خارج از public ذخیره و فقط از مسیر کنترل‌شده و مجاز تحویل شود.

## ۶. Cron و queue

Laravel Scheduler باید هر دقیقه اجرا شود:

    * * * * * cd /home/{CPANEL_USER}/apps/telegram-ads && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1

منبع رسمی: [Laravel Task Scheduling](https://laravel.com/docs/13.x/scheduling)

در هاست بدون Supervisor، worker کوتاه‌عمر را با lock اجرا کنید تا اجرای دقیقه بعد با اجرای قبلی overlap نکند:

    * * * * * /usr/bin/flock -n /home/{CPANEL_USER}/tmp/telegram-ads-queue.lock sh -c 'cd /home/{CPANEL_USER}/apps/telegram-ads && /opt/cpanel/ea-php83/root/usr/bin/php artisan queue:work database --queue=high,default,broadcasts --stop-when-empty --max-time=50 --timeout=40 --tries=3' >> /home/{CPANEL_USER}/apps/telegram-ads/storage/logs/queue-cron.log 2>&1

اگر flock روی هاست موجود نیست، Job زمان‌بندی‌شده‌ای با withoutOverlapping در Laravel تعریف کنید یا از قابلیت Cron lock پنل استفاده کنید. دو worker هم‌زمان بدون طراحی concurrency می‌توانند ارسال تکراری یا فشار بیش از حد ایجاد کنند.

`SendBroadcastBatch` در هر Job حداکثر ۱۰ دریافت‌کننده را پردازش می‌کند تا با worker کوتاه‌عمر cPanel سازگار بماند. پیش از ارسال انبوه واقعی، محدودیت نرخ و پاسخ `retry_after` تلگرام را با حجم واقعی آزمایش کنید؛ crash شبکه در فاصله ارسال پیام و ثبت نتیجه همچنان می‌تواند به ارسال تکراری منجر شود.

قاعده timeout:

- هیچ ارسال همگانی یا sync طولانی در HTTP request انجام نشود.
- هر Job باید کوچک، idempotent و کوتاه‌تر از worker timeout باشد.
- callback پرداخت سریع ثبت شود و پردازش سنگین به queue منتقل شود.
- خطای Telegram 429 با مقدار retry_after دوباره زمان‌بندی شود.
- failed_jobs و queue-cron.log روزانه پایش شوند.

بررسی:

    php artisan queue:work database --once -v
    php artisan schedule:list
    php artisan queue:monitor database:default --max=100

منبع رسمی: [Laravel Queues](https://laravel.com/docs/13.x/queues)

## ۷. فعال‌سازی اتصال‌ها

پس از deploy و فقط وقتی routeها وجود دارند:

1. Telegram webhook را طبق [TELEGRAM_SETUP.md](TELEGRAM_SETUP.md) ثبت کنید.
2. callback زرین‌پی را روی URL مستندشده قرار دهید.
3. NOWPayments IPN را دقیقاً روی https://{APP_DOMAIN}/webhooks/nowpayments قرار دهید و secret را تنظیم کنید.
4. برای هر provider یک پرداخت کم‌مقدار end-to-end انجام دهید.
5. پرداخت تکراری، callback جعلی، timeout، underpayment و retry را آزمایش کنید.

## ۸. انتشار امن نسخه جدید

ترتیب پیشنهادی:

    php artisan down --render="errors::503"
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    php artisan migrate --force
    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan queue:restart
    php artisan up

روی هاست بدون worker دائمی، queue:restart ضروری نیست اما بی‌ضرر است. اگر migration بزرگ است، برنامه rollback و پنجره نگهداری تعریف کنید.

## ۹. Backup و پایش

حداقل:

- backup رمز‌شده MySQL روزانه
- backup رمز‌شده private storage
- نگهداری backup در حساب یا محل جدا از cPanel
- آزمون restore دوره‌ای
- هشدار برای خطای webhook، failed job، کاهش فضای دیسک و انقضای SSL
- redaction توکن، PAN، کد ملی و payload حساس در log

APP_KEY برای بازیابی داده رمز‌شده و KYC_HMAC_KEY برای blind index، جست‌وجو و تشخیص تکرار حیاتی‌اند؛ KYC_HMAC_KEY کلید رمزگشایی نیست. backup کلیدها باید جدا، محدود و ثبت‌شده باشد؛ قرارگرفتن آن‌ها کنار backup داده، مزیت کنترل دسترسی را کاهش می‌دهد.

## ۱۰. چک نهایی

- APP_DEBUG برابر false است.
- دامنه فقط با HTTPS باز می‌شود.
- document root پوشه public است.
- .env و storage از وب قابل دریافت نیستند.
- php artisan route:list تمام endpointهای مورد انتظار را نشان می‌دهد.
- migration و queue سالم‌اند.
- webhook secret بررسی می‌شود.
- initData Mini App سمت سرور اعتبارسنجی می‌شود.
- callback/IPN تکراری موجودی را دوباره افزایش نمی‌دهد.
- مدارک KYC public URL ندارند.
- متن Terms، Privacy و Payment Support در دسترس است.
- ریسک پرداخت مستقیم داخل Mini App از نظر Telegram Policy کتبی بررسی شده است.
