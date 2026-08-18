# امنیت، KYC و نگهداری داده

این سند baseline فنی و عملیاتی است و جایگزین ارزیابی حقوقی، PCI DSS، مقررات بانکی یا قانون حفاظت داده حوزه فعالیت نیست.

## هدف KYC

احراز هویت برای پرداخت ریالی پیش از اولین واریز الزامی است تا:

- مالکیت هویت و کارت ثبت‌شده بررسی شود.
- استفاده غیرمجاز از کارت کاهش یابد.
- تراکنش مشکوک قابل پیگیری باشد.
- سطح دسترسی پرداخت ریالی کنترل شود.

KYC نباید به‌عنوان تضمین نبود تقلب معرفی شود.

## اطلاعات درخواستی

- شماره همراه
- نام و نام خانوادگی قانونی
- کد ملی، فقط اگر مبنای قانونی و نیاز عملیاتی تأیید شده است
- تصویر واضح کارت ملی به‌تنهایی
- تصویر شخص در حالی که کارت ملی را در دست دارد
- نام صاحب حساب یا کارت بانکی
- شماره کارت مورد استفاده برای واریز

عبارت «کارت ملی خالی» در UI استفاده نشود؛ متن صحیح «تصویر کارت ملی به‌تنهایی» است.

فقط اطلاعاتی جمع‌آوری شود که واقعاً برای هدف اعلام‌شده لازم است. TODO-LEGAL باید تعیین کند کد ملی کامل و PAN کامل لازم‌اند یا می‌توان از token، HMAC، BIN و چهار رقم آخر استفاده کرد.

## متن رضایت پیشنهادی

> تأیید می‌کنم اطلاعات واردشده صحیح است، مالک کارت بانکی ثبت‌شده هستم و با پردازش این اطلاعات برای احراز هویت، امنیت پرداخت و رسیدگی به تخلفات احتمالی مطابق سیاست حریم خصوصی موافقم.

این متن بدون Privacy Notice کامل و مبنای قانونی کافی نیست.

## وضعیت‌ها

| وضعیت | معنی |
|---|---|
| draft | تکمیل نشده |
| submitted | برای بررسی ارسال شده |
| under_review | اپراتور بررسی را آغاز کرده |
| changes_requested | اصلاح یا مدرک جدید لازم است |
| approved | هویت تأیید شده |
| rejected_permanent | با فرایند عادی قابل تأیید نیست |
| revoked | تأیید قبلی لغو شده |

Reason codeهای فعلی:

- unreadable_id
- selfie_mismatch
- card_owner_mismatch
- phone_mismatch
- duplicate_identity
- document_expired
- suspected_fraud
- missing_documents
- other

یادداشت ادمین نباید شامل توهین، حدس غیرضروری یا داده حساس اضافی باشد.

## گردش کار اپراتور

1. درخواست submitted را claim کنید و under_review قرار دهید.
2. کامل‌بودن اطلاعات و کیفیت تصاویر را بررسی کنید.
3. نام قانونی، تصویر و اطلاعات کارت ثبت‌شده را طبق checklist مقایسه کنید.
4. روش verification و نتیجه هر استعلام مجاز را ثبت کنید.
5. در صورت نقص، changes_requested و reason code مشخص ثبت کنید.
6. در صورت تأیید، funding card را approved کنید.
7. فقط بعد از تأیید شماره همراه و کارت، سطح کاربر را rial_verified کنید.
8. تمام تصمیم‌ها در kyc_reviews و audit_logs ثبت شوند.

در card_owner_mismatch:

- funding card تأیید نمی‌شود.
- KYC level برابر base باقی می‌ماند.
- واریز ریالی غیرفعال می‌ماند.
- کاربر دلیل قابل فهم و امکان اصلاح دریافت می‌کند.

متن کاربر:

> شماره کارت بانکی با اطلاعات هویتی شما مطابقت ندارد. حساب شما در سطح پایه باقی می‌ماند و واریز ریالی فعال نمی‌شود. لطفاً کارت متعلق به خودتان را ثبت و درخواست را دوباره ارسال کنید.

## محدودیت تطبیق کارت در ZarinPay

مستند عمومی فعلی ZarinPay در نمونه callback و verify فقط authority، order_id، payment_id و amount را نشان می‌دهد. PAN، PAN masked، نام صاحب کارت یا کد ملی پرداخت‌کننده در آن تضمین نشده‌اند.

پیامد:

- کارت واردشده در KYC را می‌توان دستی بررسی و در allowlist داخلی ثبت کرد.
- با داده مستند فعلی نمی‌توان ثابت کرد پرداخت واقعی با همان کارت انجام شده است.
- verification_method نباید به‌اشتباه automatic_gateway_match ثبت شود.
- برای enforce واقعی، قرارداد و field مستند provider یا سرویس استعلام جداگانه لازم است.

تا آن زمان UI نباید عبارت «کارت پرداخت به‌صورت خودکار با کارت ملی تطبیق شد» نشان دهد.

## ذخیره فایل

- مدارک KYC روی disk خصوصی خارج از public ذخیره شوند.
- برای KYC از public disk و storage:link استفاده نشود.
- نام فایل کاربر به‌عنوان storage path استفاده نشود.
- storage key تصادفی و غیرقابل حدس باشد.
- MIME با محتوای واقعی بررسی شود، نه فقط extension.
- اندازه و ابعاد محدود شوند.
- SVG، HTML و فایل اجرایی پذیرفته نشوند.
- metadata تصویر در صورت امکان پاک شود.
- SHA-256 برای تشخیص duplicate ذخیره شود.
- فایل در حالت سکون رمزگذاری شود.
- تحویل فایل فقط با authorization و URL کوتاه‌عمر یا stream کنترل‌شده باشد.
- Content-Disposition برابر attachment و Content-Type صحیح تنظیم شود.

اسکن malware و پردازش تصویر باید خارج از request و در queue محدود اجرا شوند.

## PAN و داده بانکی

PAN داده حساس پرداخت است:

- هرگز در log، analytics، audit diff، notification یا support ticket کامل نمایش داده نشود.
- رابط عمومی فقط BIN لازم و چهار رقم آخر را نشان دهد.
- جست‌وجو با HMAC جداگانه انجام شود، نه plaintext.
- encryption key و HMAC key جدا باشند.
- دسترسی decrypt فقط برای role محدود و عملیات مصوب باشد.
- clipboard، export و screenshot اپراتور تا حد امکان محدود شوند.

schema فعلی pan_encrypted را دارد، اما وجود schema مجوز نگهداری PAN کامل نیست. TODO-LEGAL و TODO-SECURITY باید تصمیم بگیرند:

- آیا PAN کامل واقعاً لازم است؟
- provider و قرارداد merchant چه الزاماتی دارند؟
- آیا tokenization یا فقط last4 کافی است؟
- دامنه تعهد PCI DSS چیست؟

## داده قابل جست‌وجو

فیلدهایی مانند legal_name_search نباید نسخه plaintext غیرضروری از داده محرمانه باشند. normalization و داده قابل جست‌وجو باید کمینه، مستند و دارای access control باشند.

برای کد ملی:

- مقدار اصلی encrypted
- blind index با HMAC-SHA256 و secret مستقل
- مقایسه constant-time در نقاط حساس
- عدم نمایش در listها

## کنترل دسترسی

حداقل roleها:

- kyc_reviewer
- support
- ads_operator
- finance
- super_admin

کنترل‌ها:

- least privilege
- MFA برای ادمین در صورت امکان
- session کوتاه و logout دستگاه‌ها
- re-authentication برای مشاهده یا export داده حساس
- audit مشاهده، دانلود، تأیید، رد، ویرایش و حذف
- تفکیک وظایف KYC و مالی
- غیرفعال‌سازی فوری ادمین خارج‌شده
- بازبینی فصلی permissions

super_admin بودن نباید باعث شود داده حساس به‌طور پیش‌فرض در dashboard نمایش داده شود.

## رمزگذاری و کلیدها

- APP_KEY برای Laravel encryption حیاتی است.
- KYC_HMAC_KEY باید مستقل و تصادفی باشد.
- secretهای provider و Telegram جدا هستند.
- secret در .env سرور یا secret manager هاست نگهداری شود.
- backup کلید از backup داده جدا باشد.
- rotation plan و APP_PREVIOUS_KEYS پیش از تعویض APP_KEY آزمایش شود.
- rotation نباید داده قبلی را غیرقابل بازیابی کند.

هرگز private key یا seed phrase کیف پول خزانه در cPanel ذخیره نشود.

## Log و audit

در log فنی redaction شود:

- Bot token
- webhook secret
- Access Token و API key
- IPN secret
- PAN و کد ملی
- تصویر یا storage key قابل دسترس
- raw initData
- raw callback دارای داده حساس
- password و session cookie

audit_logs باید actor، action، subject، زمان، correlation ID و before/after redacted را نگه دارد. audit با application log یکی نیست و نباید به‌آسانی توسط اپراتور پاک شود.

## Retention

مقادیر زیر تا تصویب مالک و مشاور حقوقی placeholder هستند:

| داده | مدت | رویداد شروع | اقدام پایان |
|---|---|---|---|
| KYC draft تکمیل‌نشده | {KYC_DRAFT_RETENTION_DAYS} | آخرین تغییر | حذف امن |
| KYC رد یا اصلاح‌نشده | {KYC_REJECTED_RETENTION_DAYS} | تصمیم نهایی | حذف یا anonymize |
| KYC تأییدشده | {KYC_VERIFIED_RETENTION_DAYS} | پایان رابطه/آخرین تراکنش | حذف یا archive قانونی |
| فایل proof کمپین | {CAMPAIGN_PROOF_RETENTION_DAYS} | پایان کمپین | حذف امن |
| payment webhook redacted | {PAYMENT_EVENT_RETENTION_DAYS} | دریافت رویداد | حذف |
| audit log | {AUDIT_RETENTION_DAYS} | ایجاد | archive یا حذف کنترل‌شده |
| support attachment | {SUPPORT_FILE_RETENTION_DAYS} | بسته‌شدن تیکت | حذف |
| backup | {BACKUP_RETENTION_DAYS} | ساخت backup | expiry خودکار |

TODO-LEGAL:

- مبنای قانونی جمع‌آوری
- سن مجاز کاربر
- محل پردازش و انتقال برون‌مرزی
- درخواست دسترسی، اصلاح و حذف
- legal hold و تعارض آن با حذف
- اطلاع‌رسانی breach
- مسئول/پردازشگر داده

فیلد expires_at به‌تنهایی فایل را حذف نمی‌کند؛ Job حذف، audit نتیجه و پاک‌سازی backup باید پیاده و تست شوند.

## Incident response

در افشای احتمالی:

1. دسترسی را محدود و incident ID ایجاد کنید.
2. token یا secret در معرض را rotate کنید.
3. log و شواهد را بدون دستکاری حفظ کنید.
4. دامنه کاربران و داده‌های متأثر را مشخص کنید.
5. الزامات اطلاع‌رسانی قانونی و قراردادی را اجرا کنید.
6. callback، queue و پرداخت را در صورت نیاز feature-flag کنید.
7. root cause و اقدام اصلاحی ثبت کنید.

نشانی SECURITY_CONTACT_EMAIL و مسئول incident باید پیش از Production تعیین شوند.

## آزمون امنیتی اجباری

- IDOR روی فایل KYC
- upload فایل جعلی و polyglot
- MIME و extension mismatch
- path traversal
- XSS در نام فایل و admin note
- دسترسی role نامرتبط
- audit redaction
- replay تصمیم KYC
- race در دو reviewer
- duplicate national ID
- duplicate PAN HMAC
- backup restore
- key rotation
- حذف retention
- callback جعلی و تکراری
- initData جعلی و منقضی

Production فقط پس از رفع یافته‌های بحرانی و بالا فعال شود.

