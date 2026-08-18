# معماری و دامنه نسخه اول

## هدف

سامانه بین مشتری و عملیات مدیریت‌شده تبلیغات قرار می‌گیرد. مشتری سفارش، پرداخت و گزارش را در Mini App می‌بیند؛ ادمین KYC، محتوا، پرداخت و عملیات را در پنل وب مدیریت می‌کند؛ اپراتور کمپین را در نسخه اول به‌صورت دستی در Telegram Ads ثبت و آمار را به شکل snapshot وارد می‌کند.

این سرویس مستقل و غیررسمی است و نباید با نام، لوگو یا متن رابط، وابستگی رسمی به Telegram را القا کند.

## محدودیت اصلی یکپارچه‌سازی

در مستندات عمومی رسمی بررسی‌شده، API عمومی مستندی برای ایجاد، ویرایش، توقف یا دریافت آمار کمپین تبلیغ‌دهنده Telegram Ads وجود ندارد. متدهای Sponsored Messages در Telegram API برای کلاینت‌هایی هستند که تبلیغ آماده را دریافت، نمایش و interaction آن را گزارش می‌کنند.

نتیجه معماری:

- Telegram Ads یک سامانه بیرونی اپراتورمحور است.
- شناسه کمپین خارجی و وضعیت آن دستی ثبت می‌شوند.
- snapshot آمار دستی و دارای مدرک اختیاری است.
- هیچ Bot API token، MTProto session یا scraping برای مدیریت Ads استفاده نمی‌شود.
- اگر بعداً API رسمی و مجاز ارائه شد، adapter جدید جایگزین ورودی دستی می‌شود؛ مدل Order و snapshot تغییر بنیادی نمی‌کند.

## نمای سطح بالا

    Telegram user
        |
        | signed Mini App initData
        v
    Customer Web App ---- Laravel application ---- Admin Web Panel
                              |       |
                              |       +---- database queue / cron
                              |
                              +---- MySQL: orders, KYC, ledger, audit
                              +---- private storage: KYC/proofs
                              +---- Telegram Bot API webhook
                              +---- ZarinPay callback + server verify
                              +---- NOWPayments signed IPN
                              |
                         Operator task
                              |
                              v
                    Telegram Ads official UI
                              |
                         manual status and
                         metric snapshots

## ماژول‌های دامنه

### هویت کاربر

User هویت تلگرامی، زبان، عکس، شماره همراه، سطح KYC و پرچم‌های ریسک را نگه می‌دارد. داده initData فقط پس از اعتبارسنجی امضای سمت سرور قابل اعتماد است.

سطوح فعلی:

- base
- rial_verified
- restricted

### ادمین و مجوزها

Admin دارای role، permissions و وضعیت فعال است. super_admin تمام مجوزها را دارد. عملیات حساس باید علاوه بر authorization در audit_logs ثبت شوند.

### KYC و فایل خصوصی

- kyc_applications: نسخه‌بندی درخواست و نتیجه بررسی
- kyc_documents: اتصال نوع مدرک به فایل خصوصی
- private_files: محل، hash، انقضا و پرچم رمزگذاری
- funding_cards: PAN رمز‌شده، HMAC جست‌وجو، BIN، چهار رقم آخر و نتیجه بررسی
- kyc_reviews: تصمیم، reason code، یادداشت و checklist اپراتور

وجود فیلد PAN به معنی مجازبودن ذخیره آن نیست. تصمیم نهایی باید بر پایه data minimization، الزامات قراردادی درگاه و ارزیابی حقوقی/PCI انجام شود.

### سفارش و کمپین

- orders: قیمت snapshot‌شده، وضعیت سفارش و پرداخت
- campaign_revisions: نسخه‌های متن، مقصد، هدف نمایش، frequency cap، plan و CPM
- campaign_targets: کانال دستی یا پیشنهادی همراه snapshot اعضا
- order_status_events: تاریخچه تغییر وضعیت با actor و correlation ID
- fund_holds: رزرو بودجه تا تعیین تکلیف سفارش

ویرایش محتوای ردشده باید revision جدید ایجاد کند. نسخه‌ای که در Telegram Ads ثبت شده است قفل می‌شود و بازنویسی درجا ندارد.

### کاتالوگ کانال پیشنهادی

- target_categories: عنوان و توضیح فارسی/انگلیسی
- suggested_channels: مشخصات، زبان، شمار اعضا و زمان آخرین بررسی
- target_category_channels: ترتیب کانال در دسته

قاعده محصول «حداکثر ۳۰ کانال در هر دسته» باید در validation و پنل ادمین enforce شود. migration فعلی فقط یکتایی position را تضمین می‌کند و سقف ۳۰ را در دیتابیس enforce نمی‌کند.

عضویت در فهرست پیشنهادی به معنی مالکیت، شراکت یا تضمین نمایش تبلیغ در کانال نیست.

### اتصال اپراتوری Telegram Ads

- operator_tasks: صف کار، مسئول، مهلت و context
- telegram_submissions: شناسه خارجی، حساب عملیاتی، وضعیت، دلیل رد و مدرک
- campaign_metric_snapshots: مقادیر تجمعی آمار در یک زمان مشخص

### پرداخت و دفترکل

- payment_intents: قصد پرداخت برای wallet_top_up یا order_payment
- payment_attempts: authority، redirect و پاسخ redacted درگاه
- payment_webhook_events: رویداد idempotent callback/IPN
- payout_requests: مدل داده درخواست‌های استرداد به مبدأ؛ route پردازش اپراتوری آن هنوز پیاده نشده است
- ledger_accounts، ledger_transactions و ledger_entries: دفترکل دوطرفه
- fund_holds: وجه رزروشده سفارش

هر اثر مالی باید در یک تراکنش دیتابیس و با idempotency_key یکتا ثبت شود. پاسخ callback به‌تنهایی مجوز افزایش موجودی نیست.

### پشتیبانی، سیاست و ارتباط

- support_tickets و ticket_messages
- policy_versions و policy_acceptances
- broadcasts و broadcast_recipients
- settings
- audit_logs

ارسال همگانی در HTTP request انجام نمی‌شود. دریافت‌کنندگان در صف ثبت و دسته‌ای پردازش می‌شوند؛ Job فعلی retry_after تلگرام را parse نمی‌کند و اندازه دسته/timeout آن پیش از ارسال انبوه Production باید اصلاح و آزمون شود.

## جریان سفارش

    draft
      -> awaiting_payment
      -> support_review
      -> changes_requested -> revision جدید -> support_review
      -> queued_for_telegram
      -> telegram_review
      -> telegram_approved
      -> scheduled
      -> active
      -> pause_requested -> paused
      -> resume_requested -> active
      -> completed

شاخه‌های استثنا:

- telegram_rejected
- cancelled_by_support
- cancelled_by_user
- manual_attention

تغییر وضعیت فقط از CampaignTransitionService مجاز است و این service هم‌زمان order_status_events و audit ایجاد می‌کند؛ تست‌های واحد مسیرهای اصلی آن وجود دارند. هر controller یا Job تازه نیز باید از همین service استفاده کند و update مستقیم status مجاز نیست.

## جریان KYC ریالی

    draft -> submitted -> under_review
                         -> changes_requested -> submitted
                         -> approved
                         -> rejected_permanent
    approved -> revoked

فعال‌شدن rial_verified فقط وقتی مجاز است که:

1. درخواست KYC توسط ادمین تأیید شده باشد.
2. شماره همراه تأیید شده باشد.
3. حداقل یک funding card در وضعیت approved وجود داشته باشد.

در صورت مغایرت صاحب کارت، reason code برابر card_owner_mismatch ثبت می‌شود، سطح کاربر base باقی می‌ماند و پرداخت ریالی فعال نمی‌شود.

## قواعد مالی

- مبلغ ریالی در واحد IRR و integer ذخیره می‌شود.
- تومان فقط واحد نمایش است: ۱ تومان برابر ۱۰ ریال.
- اعشار دارایی دیجیتال در decimal ثابت ذخیره می‌شود، نه float.
- service_markup_bps مقدار کارمزد را در basis point نگه می‌دارد؛ ۱۵۰۰ برابر ۱۵٪ است.
- درصد، کارمزد و مبلغ‌های تبدیل‌شده هر سفارش هنگام quote ذخیره می‌شوند. نرخ خام و منبع نرخ هنوز روی خود سفارش snapshot نمی‌شوند و تا تکمیل schema نباید ادعای snapshot کامل نرخ شود.
- quote_expires_at زمان انقضای نرخ تبدیل را مشخص می‌کند.
- موجودی پرداختی و اعتبار تبلیغاتی غیرقابل برداشت باید حساب‌های دفترکل جدا داشته باشند.
- بازپرداخت نقدی و بازگشت اعتبار داخلی دو رویداد متفاوت هستند.

نام و واحد دارایی مورد استفاده Telegram Ads باید پیش از انتشار با صورتحساب واقعی حساب عملیاتی و مستندات روز تطبیق داده شود؛ نام فیلدهای gram در schema به‌تنهایی مرجع مالی کافی نیست.

## آمار

snapshotها تجمعی هستند:

- impressions
- joins
- bot_starts
- spend_gram
- remaining_budget_gram
- as_of_at
- source
- proof_file_id
- recorded_by

مدل داده امکان اصلاح با رکورد جدید و supersedes_id را دارد و حذف یا ویرایش بی‌ردپا مجاز نیست. ورودی فعلی پنل proof و supersedes_id را دریافت نمی‌کند و نمودار فعلی روند تجمعی را نشان می‌دهد؛ ورودی اصلاحی و نمودار تفاضلی روزانه هنوز باید پیاده شوند.

## زمان و بومی‌سازی

- زمان در دیتابیس UTC ذخیره می‌شود.
- نمایش فارسی می‌تواند شمسی و با timezone برابر Asia/Tehran باشد.
- نمایش انگلیسی میلادی است.
- رابط فارسی موجودی تومان و دلار را نشان می‌دهد.
- رابط انگلیسی فقط معادل دلار را نشان می‌دهد.
- منبع نرخ و زمان آخرین به‌روزرسانی باید کنار مقدار تقریبی نمایش داده شود؛ این نمایش در رابط فعلی کامل نیست.
- رابط محصول در نسخه فعلی Light-only است؛ ورودی theme تاریک تلگرام نباید خوانایی را خراب کند.

## خارج از دامنه V1

- اتوماسیون غیررسمی پنل Telegram Ads
- تضمین زمان بررسی یا نتیجه تبلیغ
- تضمین آمار لحظه‌ای
- نگهداری seed phrase یا private key کیف پول روی cPanel
- برداشت خودکار رمزارز
- تطبیق قطعی صاحب کارت صرفاً از callback فعلی ZarinPay
- فعال‌کردن پرداخت مستقیم داخل Mini App بدون تأیید حقوقی و Policy

## تصمیم‌های لازم پیش از Production

- TODO-LEGAL: مجازبودن مدل واسطه/managed service و شرایط حساب Telegram Ads
- TODO-LEGAL: مسیر مجاز پرداخت خدمات دیجیتال داخل و خارج Telegram
- TODO-OWNER: نام حقوقی، دامنه، ایمیل و نشانی پشتیبانی
- TODO-OWNER: حداقل سفارش، کارمزد درگاه، مالیات و منبع نرخ
- TODO-LEGAL: سیاست برداشت، بازپرداخت نقدی و اعتبار غیرقابل برداشت
- TODO-LEGAL: مبنای KYC، retention و پاسخ به درخواست حذف
- TODO-SECURITY: مدیریت کلید، backup، incident response و آزمون نفوذ
