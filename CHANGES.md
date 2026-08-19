# TelegramAdsBot — تغییرات نسخه هشتم (Patch v8)

این پوشه شامل فایل‌های تغییر یافته برای حل سه مشکل گزارش‌شده توسط کاربر است.

---

## مشکل ۱: تأیید احراز هویت → پروفایل + نوتیف شمارش احراز هویت

### الف) شمارش احراز هویت‌های در انتظار، پس از تأیید کم نمی‌شد

**علت:** در `AppServiceProvider::boot()` شمارش KYCهای pending در کش
(`admin:pending-kyc-count`) با TTL ۶۰ ثانیه ذخیره می‌شد، ولی هیچ‌جا بعد از
تغییر وضعیت KYC این کش invalidate نمی‌شد. پس حتی بعد از approve/reject،
بَجِ سایدبار تا ۶۰ ثانیه عدد قدیمی رو نشون می‌داد.

**راه‌حل:** در `KycService` یک متد خصوصی `invalidatePendingKycCache()`
اضافه شد که بعد از هر تغییر وضعیت (submit, beginReview, approve,
requestChanges, rejectPermanently, revoke) صدا زده می‌شه.

### ب) مشخصات کاربر پس از تأیید در پروفایل نیومد

**علت:** صفحه‌ی identity وقتی `$isApproved` بود فقط کارت‌های تأییدشده رو
نشون می‌داد، ولی نام قانونی، کد ملی، تاریخ تأیید و... نمایش داده
نمی‌شد.

**راه‌حل:** یک سکشن جدید «اطلاعات هویتی تأییدشده» به صفحه‌ی identity
(`/app/identity`) اضافه شد که شامل:
- نام و نام خانوادگی قانونی (decrypted)
- کد ملی (mask شده: ۱۲۳******۷۸۹)
- شماره تلفن تأییدشده
- تاریخ تأیید

همچنین یک سکشن خلاصه به صفحه‌ی account (`/app/account`) اضافه شد که همون
اطلاعات رو به‌صورت خلاصه نشون می‌ده.

---

## مشکل ۲: فیلد مبلغ شارژ (تومان) باید اعداد فارسی رو قبول بکنه

**علت:** فیلد مبلغ شارژ با `type="number"` بود. مرورگرها برای `type="number"`
کاراکترهای فارسی رو به‌کلی رد می‌کنند (قبل از اینکه JS ببینه)؛ پس کاربر
که کیبورد فارسی داشت، عددش وارد فیلد نمی‌شد. ولی فیلد مبلغ دلاری از
`type="text"` + `data-persian-digits` استفاده می‌کرد که درست کار می‌کرد.

**راه‌حل:**
- در `wallet/index.blade.php` فیلد `amount_toman` از `type="number"` به
  `type="text" inputmode="numeric" data-persian-digits data-amount-field
  data-amount-integer` تغییر کرد. دقیقاً همان الگوی فیلد دلاری.
- اسکریپت sanitiser جاوااسکریپت به‌روزرسانی شد تا دو حالت داشته باشد:
  - حالت decimal (برای فیلد دلاری): نقطه هم قبول می‌کند.
  - حالت integer (برای فیلد تومانی، با `data-amount-integer`): فقط رقم.
- در `PaymentController::topUpWithZarinPay()` قبل از validation،
  `IranianIdentity::digits()` برای تبدیل اعداد فارسی به لاتین استفاده می‌شه
  (safety net سمت سرور، در صورتی که JS غیرفعال بود یا کش مرورگر قدیمی بود).

---

## مشکل ۳: ارور ZarinPay callback is missing its order reference or authority

**علت:** طبق docs رسمی ZarinPay
(https://github.com/miladrajabi2002/zarinpay-doc) بعد از پرداخت موفق،
ZarinPay یک POST با body زیر به `callback_url` می‌زنه:

```json
{ "authority": "A000...grjfza5o6", "order_id": "ORD123" }
```

ولی در عمل، لاحظه شد که مرورگر کاربر به `callback_url` می‌رسه بدون اینکه
`authority` و `order_id` در request باشن (احتمالاً ZarinPay notification
رو به‌صورت webhook جداگانه server-to-server می‌فرسته و سپس کاربر رو با
URL خالی redirect می‌کنه). کنترلر قبلی در این حالت exception می‌زد و
پیام ترسناک «تأیید پرداخت انجام نشد» به کاربر نشون داده می‌شد.

**راه‌حل:** `PaymentController::zarinPayCallback()` بازنویسی شد:

1. **پارس کردن چند-منبعی:** اول از `$request->input()` می‌خونه (که شامل
   query string، form body و JSON body می‌شه). اگه پیدا نشد، خودمان
   raw body رو به‌صورت JSON پارس می‌کنیم (safety net برای وقتی
   Content-Type درست ست نشده).

2. **Path A (پارامترها موجود):** مثل قبل، `verifyZarinPay()` صدا زده
   می‌شه. در صورت شکست، به‌جای خطا، intent رو با merchant_reference
   پیدا می‌کنیم و بر اساس وضعیت فعلی‌اش redirect می‌کنیم.

3. **Path B (پارامترها خالی):** این یعنی مرورگر کاربر بدون پارامتر
   رسیده. آخرین PaymentIntent کاربر رو پیدا می‌کنیم و بر اساس وضعیت
   فعلی‌اش (که ممکنه توسط webhook قبلاً settled شده باشه) redirect
   می‌کنیم:
   - `Succeeded`: پیام موفقیت.
   - `Pending/Verifying`: پیام «در حال پردازش».
   - `ManualReview`: پیام «در حال بررسی دستی».
   - سایر: پیام خطا.

4. **notification فقط در Path A:** برای جلوگیری از duplicate notification
   (وقتی کاربر صفحه رو refresh می‌کنه)، notification فقط در Path A ارسال
   می‌شه.

---

## فایل‌های تغییر یافته

| فایل | تغییر |
|------|-------|
| `app/Services/KycService.php` | اضافه شدن متد `invalidatePendingKycCache()` و صدا زدن آن بعد از هر تغییر وضعیت. |
| `app/Http/Controllers/MiniApp/PageController.php` | متد `account()` حالا آخرین KycApplication تأییدشده رو load می‌کنه. |
| `app/Http/Controllers/MiniApp/PaymentController.php` | `topUpWithZarinPay()` اعداد فارسی رو normalize می‌کنه. `zarinPayCallback()` بازنویسی شد تا پارامترهای خالی رو تحمل کنه. |
| `resources/views/app/identity/show.blade.php` | سکشن «اطلاعات هویتی تأییدشده» وقتی KYC approve شده. |
| `resources/views/app/account.blade.php` | سکشن خلاصه «اطلاعات هویتی تأییدشده» در پروفایل. |
| `resources/views/app/wallet/index.blade.php` | فیلد amount_toman از type=number به type=text + data-persian-digits + data-amount-integer. اسکریپت sanitiser برای پشتیبانی از integer-only mode. |

---

## نحوه‌ی استقرار

این ۶ فایل رو روی پروژه‌ی فعلی‌ت کپی کن. سپس:

```bash
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

(اختیاری) برای invalidate کردن کش فعلی pending-KYC count:

```bash
php artisan tinker
>>> Cache::forget('admin:pending-kyc-count');
```

---

## تست

### مشکل ۱: KYC approve → پروفایل + شمارش

1. به `/admin/kyc` برو و یک KYC را approve کن.
2. بَجِ شمارش pending KYC در سایدبار باید فوراً یکی کم بشه (بدون ۶۰ ثانیه
   صبر).
3. کاربر در مینی‌اپ به `/app/identity` بره. سکشن «اطلاعات هویتی
   تأییدشده» باید نام قانونی، کد ملی mask شده و تاریخ تأیید رو نشون بده.
4. کاربر به `/app/account` بره. سکشن خلاصه «اطلاعات هویتی تأییدشده»
   باید همون اطلاعات رو نشون بده.

### مشکل ۲: فیلد مبلغ شارژ فارسی

1. به `/app/wallet` برو.
2. کیبورد فارسی رو فعال کن و در فیلد «مبلغ شارژ» عدد `۱۰۰۰۰۰` رو
   تایپ کن. باید عدد وارد بشه و در حین تایپ به لاتین تبدیل بشه.
3. کارت رو انتخاب کن و «ورود به درگاه ZarinPay» رو بزن. باید بدون خطای
   validation به درگاه بری.

### مشکل ۳: ZarinPay callback

1. یک پرداخت ZarinPay انجام بده.
2. بعد از پرداخت، وقتی به `/payments/zarinpay/callback` redirect می‌شی،
   نباید ارور «تأیید پرداخت انجام نشد» ببینی.
3. اگه پارامترها موجوده (webhook واقعی)، پرداخت verify می‌شه و پیام
   موفقیت نشون داده می‌شه.
4. اگه پارامترها خالی بود (browser redirect بدون params)، آخرین intent
   پیدا می‌شه و بر اساس وضعیتش پیام مناسب نشون داده می‌شه:
   - succeeded → «پرداخت با موفقیت تأیید شد.»
   - pending → «پرداخت در حال پردازش است.»
   - manual review → «در حال بررسی مالی.»
