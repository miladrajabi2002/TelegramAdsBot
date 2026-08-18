# تغییرات اعمال‌شده روی TelegramAdsBot (نسخه ۳)

این پوشه شامل تمام فایل‌های تغییریافته یا جدید است که بر اساس درخواست شما روی پروژه `TelegramAdsBot` اعمال شده‌اند.
برای نصب، کافی است هر فایل را در مسیر اصلی پروژه خود کپی کنید (ساختار پوشه‌ها در ZIP دقیقا مطابق با ریشه پروژه است).

> **مرجع کلون اصلی:** https://github.com/miladrajabi2002/TelegramAdsBot

## نحوه استفاده

1. فایل ZIP را دانلود و در یک پوشه موقت extract کنید.
2. تمام فایل‌ها را روی پروژه خودتان کپی کنید (پوشه‌بندی را حفظ کنید).
3. دستورات زیر را اجرا کنید:

```bash
php artisan migrate
php artisan config:clear
php artisan view:clear
php artisan cache:clear
npm run build
php artisan route:clear

# یک‌بار دستی قیمت‌ها را refresh کنید:
php artisan rates:refresh
```

## متغیرهای env.

```env
# برند (به‌جای "Ads Platform"):
ADS_PLATFORM_BRAND="Ads Platform"

# نمایش splash loader اول اپ:
APP_SHOW_SPLASH=true
APP_SPLASH_MIN_MS=600

# قیمت دلار:
AUTOMATIC_EXCHANGE_RATE=true
PRICE_FEED_TTL_SECONDS=300
PRICE_MARKUP_PERCENT=4.0
TGJU_URL=https://call4.tgju.org/ajax.json
BONBAST_URL=https://bonbast.com/api/rates
NAVASAN_URL=https://navasan.net/api/v1/api.php
NAVASAN_API_KEY=

# SLA احراز:
KYC_SLA_FAST_MINUTES=60
KYC_SLA_MAX_HOURS=24

# عکس پروفایل:
PROFILE_PHOTO_TTL_SECONDS=21600

# سرچ کانال:
CHANNEL_SEARCH_PER_MINUTE=30
```

## رفع ۳ مشکل گزارش‌شده در نسخه ۳

### مشکل A — ارور KYC: "The KYC application was changed by another reviewer"
**دلیل:**
- در `KycController::store`، وقتی `KycApplication::create([...])` صدا می‌زدیم، `lock_version` در آرایه ست نشده بود.
- در مدل PHP، `lock_version` بعد از create برابر `null` می‌شد.
- در DB، default = 1 اعمال می‌شد.
- در `KycService::withLockedApplication`:
  - `expectedLockVersion = (int) $application->lock_version` = `(int)null` = `0`
  - `locked = ... ->findOrFail(...)` از DB → `lock_version = 1`
  - `if (1 !== 0) throw` → ارور!

**راه‌حل:**
- در `app/Http/Controllers/MiniApp/KycController.php::store`:
  - در `KycApplication::create`، `'lock_version' => 1` صراحتا set شد.
  - بعد از create، `$application->refresh()` صدا زده شد تا مقادیر default (مثل timestamps و lock_version) از DB به مدل sync بشن.

### مشکل B — عکس پروفایل لود نمی‌شه، علامت سوال میاد
**دلیل:**
- در نسخه ۲، یک Job (`RefreshUserProfilePhoto`) ساخته بودم که عکس رو به‌صورت async از Telegram دانلود و در storage محلی ذخیره می‌کرد.
- ولی این Job فقط با queue worker اجرا می‌شه (`php artisan queue:work` یا `pm2 restart tgads-queue`).
- اگر worker در حال اجرا نباشه، Job در queue table می‌ره ولی اجرا نمی‌شه. در نتیجه عکس هرگز fetch نمی‌شه و `/avatars/{id}` به 404 می‌خوره.
- کاربر درست اشاره کرد که "خیلی راحت میشه عکس پروفایل رو گرفت و لینک خود تلگرام لنک دانلودشو بزاری توی تگ img". ولی این URL شامل bot token هست (`https://api.telegram.org/file/bot{TOKEN}/photos/file_1.jpg`) و نباید expose بشه.

**راه‌حل نهایی (نسخه ۳):**
- فایل `app/Jobs/RefreshUserProfilePhoto.php` حذف شد (نیازی به queue worker نیست).
- `app/Http/Controllers/MiniApp/AvatarController.php` بازنویسی شد تا در همان لحظه (on-demand) عکس رو از Telegram fetch و cache کنه:
  1. اولین request به `/avatars/{userId}`:
     - بررسی `storage/app/avatars/` برای فایل موجود.
     - اگه نبود، با `getUserProfilePhotos` آخرین عکس رو می‌گیره.
     - با `getFile` فایل رو resolve می‌کنه.
     - عکس رو دانلود و در `storage/app/avatars/{userId}.{ext}` ذخیره می‌کنه.
     - عکس رو از storage serve می‌کنه.
  2. درخواست‌های بعدی: عکس رو از disk serve می‌کنه (بدون هیچ API call).
- route از `/avatars/{userId}/{ext}` به `/avatars/{userId}` تغییر کرد.
- با throttle `avatars` (۳۰ req/min/IP) محافظت می‌شه.
- در `SessionController::refreshProfilePhotoInBackground`، به‌جای dispatch Job، فقط `users.photo_url` رو به `https://your-domain/avatars/{id}` ست می‌کنه. کار fetch+cache در اولین request از <img> انجام می‌شه.
- در `layouts/app.blade.php` برای fallback اگه عکس لود نشد، یک `<span class="avatar-initial">` با حرف اول اسم + onerror handler اضافه شد.
- **بدون نیاز به queue worker، بدون نیاز به `storage:link`.**

### مشکل C — تبدیل خودکار اعداد فارسی به انگلیسی در لحظه
**راه‌حل:**
- در `resources/js/app.js`، یک converter پویا اضافه شد که روی هر `input` و `textarea` که عددی هست (یکی از این‌ها):
  - `type="number"`
  - `inputmode="numeric"` یا `"decimal"` یا `"tel"`
  - `pattern` شامل `[0-9` یا `[۰-۹`
  - `class="number"`
  - یا `name` یکی از فیلدهای عددی شناخته‌شده (مثل `national_id`, `card_number`, `amount_toman`, `cpm_gram`، و غیره)
- در `input` event: اعداد فارسی (۰۱۲۳۴۵۶۷۸۹) و عربی (٠١٢٣٤٥٦٧٨٩) رو به انگلیسی تبدیل می‌کنه، با حفظ caret position.
- در `paste` event: متن paste شده رو هم تبدیل می‌کنه.
- با `MutationObserver` روی inputs‌های که بعدا به DOM اضافه می‌شن هم کار می‌کنه.
- کاملاً UX-friendly: کاربر می‌بینه که عدد فارسی تایپ کرده به انگلیسی تبدیل می‌شه.

## فایل‌های تغییریافته یا جدید (نسخه ۳)

| # | مسیر | نوع | مرتبط با |
|---|---|---|---|
| 1 | `.env.example` | M | متغیرهای brand + splash + price feed |
| 2 | `app/Console/Commands/RefreshExchangeRates.php` | **NEW** | `rates:refresh` artisan command |
| 3 | `app/Http/Controllers/Admin/CatalogController.php` | M | تغییر ۷ |
| 4 | `app/Http/Controllers/MiniApp/AvatarController.php` | M v3 | مشکل B — fetch+cache در همان لحظه |
| 5 | `app/Http/Controllers/MiniApp/CampaignController.php` | M | اضافی (سرچ کانال) |
| 6 | `app/Http/Controllers/MiniApp/KycController.php` | M v3 | مشکل A — `lock_version => 1` + `refresh()` |
| 7 | `app/Http/Controllers/MiniApp/SessionController.php` | M v3 | مشکل B — فقط stamp `photo_url` |
| 8 | `app/Models/User.php` | M v2 | مشکل ۳ (HasOne) |
| 9 | `app/Providers/AppServiceProvider.php` | M v2 | throttle `avatars` |
| 10 | `app/Services/PriceFeedService.php` | M v2 | مشکل ۳ — use Setting |
| 11 | `app/Services/PricingService.php` | M | تغییر ۵ |
| 12 | `app/Services/Telegram/TelegramBotClient.php` | M v2 | timeout 8s + retry 1 + متدهای fetch |
| 13 | `app/Support/IranianIdentity.php` | M | namesLookSimilar + transliterate |
| 14 | `config/ads-platform.php` | M v2 | show_splash + splash_min_duration_ms |
| 15 | `resources/css/app.css` | M v3 | مشکل B — `.avatar` با fallback + `.avatar-initial` |
| 16 | `resources/js/app.js` | M v3 | مشکل C — auto-convert Persian digits |
| 17 | `resources/lang/en/ui.php` | M v2 | brand از config |
| 18 | `resources/lang/fa/ui.php` | M v2 | brand از config |
| 19 | `resources/views/admin/channels/index.blade.php` | M | تغییر ۷ |
| 20 | `resources/views/app/campaigns/create.blade.php` | M | اضافی (سرچ کانال) |
| 21 | `resources/views/app/entry.blade.php` | M | تغییر ۳ |
| 22 | `resources/views/app/home.blade.php` | M | تغییر ۶ |
| 23 | `resources/views/app/identity/show.blade.php` | M v2 | مشکل ۳ (HasOne) |
| 24 | `resources/views/app/wallet/index.blade.php` | M | تغییر ۶ |
| 25 | `resources/views/components/icon.blade.php` | M | آیکون‌های save/trash/close |
| 26 | `resources/views/layouts/app.blade.php` | M v3 | مشکل B — avatar با fallback |
| 27 | `routes/console.php` | M | schedule rates:refresh |
| 28 | `routes/web.php` | M v3 | route `/avatars/{userId}` (بدون ext) |

**نکته مهم:** فایل `app/Jobs/RefreshUserProfilePhoto.php` که در نسخه ۲ بود، حذف شد (دیگر لازم نیست).

## مرور تغییرات نسخه ۱ و ۲ (برای مرجع کامل)

### ۱. عکس پروفایل کاربر
- نسخه ۱: متدهای `getUserProfilePhotos`, `getFile`, `getLatestUserProfilePhotoUrl` در `TelegramBotClient`.
- نسخه ۲: Job async + ذخیره محلی. نیازمند queue worker.
- **نسخه ۳:** AvatarController که در همان لحظه fetch+cache می‌کنه. بدون نیاز به queue worker.

### ۲. منوی پایین لوکس
- HTML با indicator + glow + orb شناور.
- CSS با animation‌های springy + tooltip.
- JS با indicator move + haptic.

### ۳. حذف گیت احراز هویت
- `entry.blade.php` به loader سبک pulse.
- `app.js`: timeout ۴→۱.۵ + fast-path initData.

### ۴. رفع ارور ۵۰۰ احراز هویت
- نسخه ۲: try/catch + structured JSON + use Setting + HasOne.
- **نسخه ۳:** `lock_version => 1` + `refresh()` در `KycApplication::create`.

### ۵. قیمت دلار + ۴٪ + پشتیبان + GRAM/USD
- `PriceFeedService` با ۴ منبع پشتیبان.
- `rates:refresh` هر ۵ دقیقه.

### ۶. banner KYC با SLA
- "احراز سریع معمولاً ۶۰ دقیقه و نهایتاً ۲۴ ساعت".

### ۷. مدیریت دسته‌بندی ادمین
- نمایش fa/en، فرم ویرایش، دکمه حذف + toggle.

### اضافی — فیلدهای KYC + تطابق کارت با کد ملی
- فیلد جدید `card_holder_name` + منطق `namesLookSimilar`.

### اضافی — سرچ کانال با ID/لینک
- endpoint `/app/channels/search` + UI chip-based.

### اضافی — nowpayments بدون احراز / zarinpay با احراز
- از قبل درست بود؛ در UI واضح نوشته شده.

### رفع ۶ مشکل گزارش‌شده در نسخه ۲
- کندی ربات: timeout 8s + retry 1 + async profile photo.
- انیمیشن‌های ریز: splash + page-enter + card hover.
- ارور ۵۰۰: HasOne relationship + use Setting.
- عکس پروفایل: Job async + local storage.
- دکمه FA/EN: segmented toggle با sliding thumb.
- برند از env: `ADS_PLATFORM_BRAND`.

## فیچرهای پیشنهادی برای آینده

### الف) بهبودهای مرتبط با تبلیغات
1. A/B testing تبلیغ.
2. بازارچه خودکار CPM.
3. Schedule پیشرفشاده.
4. Multi-language targeting.
5. Geographic targeting.

### ب) بهبودهای مالی
6. Withdrawal رمزارزی.
7. Referral program.
8. گزارش‌های مالی PDF.
9. Multi-currency wallet.
10. محدودیت‌های هوشمند کارت.

### ج) بهبودهای اپراتور/ادمین
11. Bulk channel verification.
12. Templates کمپین.
13. Auto-approve KYC با API بانکی.
14. Dashboard اپراتور.
15. Audit log replay.

### د) بهبودهای UX و فنی
16. Dark mode.
17. Offline-first PWA.
18. Push notifications.
19. i18n با plurals.
20. WebSocket dashboard.

### هـ) رشد و کسب‌وکار
21. Coupon / Discount codes.
22. Tiered pricing.
23. Self-serve monthly subscriptions.
24. Telegram Stars support.
25. Analytics dashboard کاربر.

### و) امنیت
26. Two-factor authentication.
27. Rate-limited login attempts.
28. Session invalidation on password change.
29. Audit log tamper-evidence.
30. PCI-like data retention policy.

---

اگر سؤالی دارید یا می‌خواهید یکی از فیچرهای آینده را پیاده کنم، بفرمایید.
