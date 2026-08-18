# پرداخت، callback و دفترکل

## توقف انطباق پیش از Production

این پروژه یک خدمت تبلیغاتی با تحویل دیجیتال می‌فروشد و ممکن است مشمول قواعد خدمات دیجیتال باشد. Telegram اعلام کرده پرداخت کالا و خدمات دیجیتال داخل Bot و Mini App باید با Telegram Stars انجام شود. در نتیجه، اتصال مستقیم ZarinPay یا NOWPayments در همان Mini App با وجود امکان فنی، ممکن است ناقض Telegram، App Store یا Google Play Policy باشد.

تا زمان دریافت بررسی حقوقی و Policy:

- مسیرهای پرداخت مستقیم باید پشت feature flag و در Production خاموش باشند.
- عبارت «مجاز توسط تلگرام» یا «بدون محدودیت» استفاده نشود.
- گزینه Stars داخل Telegram و checkout مستقل خارج از Telegram بررسی شود.
- merchant eligibility هر provider برای کشور و مدل کسب‌وکار کتبی تأیید شود.

منبع: [Telegram Payments for Digital Goods and Services](https://core.telegram.org/bots/payments-stars)

## دو روش پرداخت کاربر

### پرداخت مستقیم سفارش

کاربر نباید مجبور باشد ابتدا کیف پول را شارژ کند:

    Order + quote
      -> PaymentIntent purpose=order_payment
      -> provider checkout
      -> authenticated callback/IPN
      -> server-side verification
      -> atomic ledger entries + order paid/hold
      -> support review

### افزایش کیف پول

    Top-up request
      -> PaymentIntent purpose=wallet_top_up
      -> provider checkout
      -> authenticated callback/IPN
      -> server-side verification
      -> atomic ledger credit

هر دو مسیر از PaymentIntent و PaymentAttempt مشترک استفاده می‌کنند. تفاوت فقط purpose و اثر دفترکل است.

## شرط پرداخت ریالی

پیش از ساخت PaymentIntent ریالی:

1. phone_verified_at مقدار داشته باشد.
2. KYC level برابر rial_verified باشد.
3. یک funding card تأییدشده انتخاب شود.
4. مبلغ، سقف و پرچم‌های ریسک بررسی شوند.

در صورت card_owner_mismatch، کاربر در سطح base باقی می‌ماند و واریز ریالی فعال نمی‌شود.

توجه: تأیید دستی کارت ثبت‌شده به معنی اثبات این نیست که پرداخت نهایی با همان کارت انجام شده است. این کنترل فقط وقتی قابل enforce است که provider یا سرویس استعلام مجاز، PAN masked یا شناسه قابل اتکای ابزار پرداخت را برگرداند.

## وضعیت‌های پرداخت

PaymentIntent:

- created
- pending
- verifying
- succeeded
- failed
- manual_review
- expired
- cancelled
- partially_refunded
- refunded
- chargeback

Order payment:

- unfunded
- pending
- paid
- partially_refunded
- refunded
- chargeback

تغییر succeeded و افزایش موجودی باید در یک database transaction انجام شود. callback تکراری فقط نتیجه قبلی را برمی‌گرداند و اثر مالی جدید ایجاد نمی‌کند.

## ZarinPay

این provider «زرین‌پی» است، نه زرین‌پال. مرجع فعلی یک مخزن عمومی شخص ثالث است:

[ZarinPay documentation](https://github.com/miladrajabi2002/zarinpay-doc)

پیش از Production باید هویت حقوقی provider، قرارداد merchant، SLA، تسویه، مسئولیت chargeback، حفاظت داده، محیط آزمایشی و کانال پشتیبانی بررسی شود. وجود Access Token به معنی انجام این بررسی‌ها نیست.

### تنظیمات

    ZARINPAY_ACCESS_TOKEN={SECRET}
    ZARINPAY_BASE_URL=https://zarinmee.ir/api
    ZARINPAY_ENABLED=false
    ZARINPAY_MOCK=false
    ZARINPAY_TIMEOUT=15
    ZARINPAY_PAYMENT_HOSTS=zarinmee.ir

Access Token فقط در backend نگهداری می‌شود و به JavaScript یا Mini App ارسال نمی‌شود.

callback در نسخه فعلی از APP_URL و route نام‌دار payments.zarinpay.callback ساخته می‌شود. ZARINPAY_CALLBACK_URL و ZARINPAY_STORE_ID در config فعلی خوانده نمی‌شوند؛ اگر قرارداد merchant به store_id نیاز دارد، ابتدا wiring آن باید در کد اضافه و تست شود.

### ایجاد پرداخت

طبق مستند فعلی:

    POST https://zarinmee.ir/api/create-payment
    Authorization: Bearer {ZARINPAY_ACCESS_TOKEN}
    Content-Type: application/json

payload:

    {
      "amount": 1150000,
      "order_id": "{UNIQUE_MERCHANT_REFERENCE}",
      "callback_url": "https://{APP_DOMAIN}/payments/zarinpay/callback",
      "type": "card",
      "customer_user_id": "{PROVIDER_SAFE_CUSTOMER_ID}",
      "description": "{SAFE_DESCRIPTION}",
      "store_id": "{OPTIONAL_STORE_ID}"
    }

قواعد:

- amount در مستند زرین‌پی به ریال است، نه تومان.
- order_id برای provider یکتا باشد.
- type فعلاً card مستند شده است.
- payment_link و authority فقط بعد از اعتبارسنجی ساختار پاسخ ذخیره شوند.
- URL برگشتی provider باید HTTPS و روی host مورد انتظار باشد.
- تراکنش مستنداً ۳۰ دقیقه اعتبار دارد.
- تکرار order_id در حالت Pending ممکن است همان لینک را برگرداند؛ برای retry پس از انقضا merchant reference جدید بسازید و ارتباط آن را با PaymentIntent حفظ کنید.

پیاده‌سازی فعلی telegram_user_id خام را به‌عنوان customer_user_id می‌فرستد و redirect را فقط از نظر شروع‌شدن با https:// کنترل می‌کند، نه allowlist دامنه provider. پیش از Production باید شناسه داخلی غیرقابل‌حدس/حداقل‌داده جایگزین و host بازگشتی صریحاً allowlist شود.

### callback

URL قابل درج:

    https://{APP_DOMAIN}/payments/zarinpay/callback

مستند فعلی می‌گوید پس از پرداخت موفق یک POST شامل authority و order_id ارسال می‌شود. این callback امضای مستندی ندارد و اثبات پرداخت نیست.

handler باید:

1. route فعلی برای سازگاری با بازگشت مرورگر GET و POST را می‌پذیرد؛ هر دو حالت باید فقط ورودی تلقی شوند و هیچ‌کدام بدون verify سروربه‌سرور اثر مالی ایجاد نکنند. محدودیت body نیز باید در وب‌سرور و برنامه اعمال شود.
2. order_id و authority را به PaymentAttempt موجود تطبیق دهد.
3. وضعیت را verifying کند.
4. از backend، verify-payment را فراخوانی کند.
5. amount، order_id و authority پاسخ verify را با رکورد داخلی مقایسه کند.
6. اثر مالی را idempotent و اتمیک ثبت کند.
7. پاسخ کاربرپسند success، pending یا failed نشان دهد.

### تأیید سرور به سرور

    POST https://zarinmee.ir/api/verify-payment
    Authorization: Bearer {ZARINPAY_ACCESS_TOKEN}
    Content-Type: application/json

payload:

    {
      "authority": "{AUTHORITY}"
    }

کدهای مستند:

| code | رفتار سامانه |
|---|---|
| 100 | اولین تأیید موفق؛ در صورت تطبیق کامل اثر مالی ثبت شود |
| 101 | قبلاً تأیید شده؛ نتیجه موجود برگردد و هرگز دوباره credit نشود |
| -1 | ناموفق |
| -53 | دسترسی غیرمجاز؛ incident و manual review |
| -54 | یافت نشد |
| -55 | طبق متن provider تراکنش هنوز منقضی نشده؛ بدون credit و با retry کنترل‌شده |

HTTP timeout، 5xx یا پاسخ نامعتبر نباید failed قطعی تلقی شود؛ به manual_review یا retry با backoff منتقل شود.

### محدودیت PAN و تطبیق صاحب کارت

در مستند عمومی فعلی ZarinPay، پاسخ create، callback و نمونه verify این موارد را تضمین نمی‌کنند:

- PAN کامل
- PAN masked
- BIN و چهار رقم آخر ابزار پرداخت واقعی
- نام صاحب کارت
- کد ملی صاحب کارت
- نتیجه تطبیق کارت با هویت

بنابراین با همین API نمی‌توان الزام «پرداخت فقط با کارت ثبت‌شده و منطبق با کارت ملی» را خودکار و قطعی enforce کرد.

راه‌های مجاز:

1. دریافت تأیید کتبی و مستند فنی جدید از ZarinPay برای بازگرداندن PAN masked یا token قابل تطبیق.
2. استفاده از سرویس استعلام بانکی/هویتی مجاز و قراردادی.
3. استفاده از درگاهی که card ownership verification مستند ارائه می‌دهد.
4. تا آن زمان، KYC و کارت در وضعیت دستی بمانند و سیستم ادعای تطبیق خودکار نکند.

نباید با حدس، field نامستند یا scraping صفحه پرداخت این خلأ پر شود.

## NOWPayments

URL قابل درج در فیلد IPN:

    https://{APP_DOMAIN}/webhooks/nowpayments

تنظیمات:

    NOWPAYMENTS_API_KEY={SECRET}
    NOWPAYMENTS_IPN_SECRET={SECRET}
    NOWPAYMENTS_BASE_URL=https://api.nowpayments.io/v1
    NOWPAYMENTS_ENABLED=false
    NOWPAYMENTS_INVOICE_HOSTS=nowpayments.io

در Payment API استاندارد، API Key و IPN Secret اجزای اصلی‌اند. Public Key فقط وقتی استفاده شود که محصول یا SDK مشخص provider مستنداً آن را لازم بداند. هیچ‌کدام در frontend قرار نمی‌گیرند.

### ساخت پرداخت

پیاده‌سازی فعلی یک Invoice با order_id یکتا، price_amount دلاری، price_currency=usd و ipn_callback_url می‌سازد. مبلغ دلاری در metadata قصد پرداخت snapshot می‌شود و success/cancel پرداخت مستقیم سفارش به همان سفارش برمی‌گردند. انتخاب pay_currency/شبکه، استعلام حداقل مبلغ provider و snapshot منبع نرخ هنوز پیاده نشده‌اند؛ UI نباید تا زمان wiring این فیلدها وانمود کند انتخاب شبکه به درخواست provider منتقل می‌شود. invoice_url برگشتی نیز پیش از redirect از نظر HTTPS/host allowlist نمی‌شود؛ پاسخ موفق بدون invoice_url فعلاً intent و سفارش را pending باقی می‌گذارد. هر دو مورد باید پیش از فعال‌سازی اصلاح شوند.

### اعتبارسنجی IPN

طبق API رسمی:

1. raw JSON دریافت شود.
2. ساختار JSON طبق روش provider به‌صورت بازگشتی بر اساس key مرتب شود.
3. JSON canonical با IPN Secret و HMAC-SHA512 امضا شود.
4. خروجی با header برابر x-nowpayments-sig و با مقایسه constant-time سنجیده شود.
5. payment_id یا event key به‌صورت unique ذخیره شود.
6. در نسخه فعلی order_id، price amount، price currency و invoice reference کنترل می‌شوند؛ نبود صریح price_amount و همچنین asset/network هنوز باید fail-closed و با PaymentIntent تطبیق داده شود.
7. فقط وضعیت نهایی مورد قبول سیاست مالی باعث credit شود.

منبع: [NOWPayments API Documentation](https://documenter.getpostman.com/view/7907941/2s93JusNJt)

نکته: IPN ممکن است برای هر تغییر وضعیت چند بار برسد. expired بودن نیز لزوماً مانع دریافت دیرهنگام deposit نیست. reconciliation دوره‌ای با Get Payment Status لازم است، اما Job آن در نسخه فعلی وجود ندارد و یک کار باقی‌مانده پیش از Production است.

ماشین وضعیت IPN باید monotonic باشد. در handler فعلی، یک event غیرنهایی دیررس می‌تواند PaymentIntent موفق را دوباره pending کند؛ پیش از Production باید وضعیت‌های terminal در برابر downgrade محافظت شوند.

وضعیت پیشنهادی:

- waiting، confirming، confirmed یا sending: pending
- partially_paid: manual_review یا تکمیل مبلغ طبق سیاست مصوب
- finished: قابل بررسی برای succeeded
- failed، expired: بدون credit
- refunded: در نسخه فعلی manual_review و task تطبیق؛ reversal فقط پس از پیاده‌سازی و تأیید reconciliation مالی

نام دقیق statusها را با نسخه جاری API و حساب merchant تطبیق دهید.

## امنیت callback و IPN

- endpointها rate limit دارند، اما limiter فعلی callback بر IP است؛ signature یا server verify کنترل اصالت اصلی است و limiter باید برای proxy/CDN محیط Production بازبینی شود.
- CSRF مرورگر برای webhook اعمال نمی‌شود؛ در عوض signature یا server verify اجباری است.
- payload کامل حساس در log نوشته نمی‌شود.
- raw body لازم برای signature فقط در محدوده پردازش نگه داشته می‌شود.
- event_key و idempotency_key unique هستند.
- پاسخ 2xx فقط پس از ثبت امن رویداد داده می‌شود.
- پردازش فعلی کوتاه و هم‌زمان است؛ هر پردازش شبکه‌ای یا reconciliation طولانی که اضافه می‌شود باید به queue منتقل شود.
- redirect کاربر هیچ اثر مالی ایجاد نمی‌کند.
- مبلغ و currency هرگز از query string صفحه success گرفته نمی‌شوند.

## دفترکل و رزرو

هیچ موجودی با update مستقیم یک عدد wallet تغییر نمی‌کند. هر تغییر از LedgerTransaction متوازن عبور می‌کند.

نمونه مفهومی افزایش کیف پول:

    Debit  provider clearing
    Credit user cash balance

نمونه رزرو سفارش:

    Debit  user available balance
    Credit user held balance

نمونه مصرف رسانه:

    Debit  user held balance
    Credit media cost payable

کارمزد خدمات:

    Debit  user held balance
    Credit service revenue

مجموع debit و credit هر LedgerTransaction، برای یک currency، باید برابر باشد. مقدارها در amount_minor و integer ذخیره می‌شوند.

## قیمت‌گذاری

فرمول پایه:

    service_fee = media_budget × service_markup_bps / 10000
    total = media_budget + service_fee + gateway_fee

قواعد:

- rounding صریح و تست‌شده باشد.
- ۱ تومان برابر ۱۰ ریال است.
- درصد سفارش موجود پس از تغییر تنظیمات ادمین عوض نمی‌شود.
- مبلغ‌های نهایی USD/GRAM همراه نرخ‌های USD/IRR و Gram/USD، حاشیه تبدیل، منبع نرخ، `quoted_at` و `quote_expires_at` روی هر سفارش snapshot می‌شوند؛ تغییر تنظیمات بعدی قیمت سفارش قبلی را بازنویسی نمی‌کند.
- بعد از انقضای quote، پرداخت با مبلغ قبلی ساخته نشود.
- فارسی: تومان و معادل دلار.
- انگلیسی: معادل دلار.

## بازپرداخت و اعتبار داخلی

این دو مفهوم جدا هستند:

- Cash balance: وجه پرداختی کاربر؛ برداشت یا استرداد آن تابع شرایط مصوب است.
- Ad credit: اعتبار غیرقابل برداشت برای سفارش تبلیغاتی بعدی.

Telegram Ads اعلام می‌کند وجه منتقل‌شده به balance رسمی و مصرف‌نشده، قابل برداشت، انتقال یا استرداد نیست. بنابراین ممکن است بودجه‌ای که در حساب عملیاتی Telegram قرار گرفته فقط به اعتبار تبلیغاتی داخلی تبدیل شود، نه پول نقد.

دامنه پیاده‌سازی فعلی دو مسیر نهایی‌سازی دارد: `reconcile-rejection` برای سفارش paid در وضعیت `telegram_rejected` و `reconcile-completion` برای سفارش paid در وضعیت `completed`. هر دو هزینه رسانه قطعی اعلام‌شده اپراتور را در دفتر کل ثبت می‌کنند، کارمزدهای قراردادشده را نهایی می‌کنند و باقیمانده واجد شرایط بودجه رسانه را به `ad_credit_restricted` می‌برند. توقف/لغو، refund یا chargeback درگاه و پردازش payout هنوز workflow کامل نهایی ندارند؛ تا تکمیل آن‌ها نباید hold متناظر را با تغییر دستی بست.

TODO-LEGAL و TODO-OWNER:

- رد پشتیبانی پیش از ثبت Telegram: مقصد مبلغ چیست؟
- رد Telegram: کدام هزینه‌ها به ad credit برمی‌گردند؟
- کارمزد خدمات و درگاه قابل استرداد است یا خیر؟
- توقف کاربر چگونه reconcile می‌شود؟
- chargeback چگونه حساب را restricted می‌کند؟
- مهلت و روش استرداد به مبدأ چیست؟

تا پاسخ این موارد تعیین نشده، متن «هیچ بازگشتی ندارد» منتشر نشود. متن دقیق باید تفاوت cash refund و internal ad credit را بیان کند.

## آزمون‌های اجباری

- پرداخت موفق
- callback جعلی
- callback قبل از redirect کاربر
- callback تکراری
- کد 101 زرین‌پی
- amount یا order_id مغایر
- انقضای پرداخت
- timeout و 5xx provider
- دو callback هم‌زمان
- NOWPayments signature نادرست
- underpayment و overpayment
- asset یا network اشتباه
- chargeback و refund
- شکست ledger پس از verify
- KYC تأییدنشده
- کارت ردشده
- quote منقضی

موفقیت پرداخت فقط وقتی اعلام شود که PaymentIntent، LedgerTransaction و وضعیت سفارش در دیتابیس سازگار باشند.
