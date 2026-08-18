# تغییرات اعمال‌شده روی TelegramAdsBot (نسخه ۲)

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

# مهم: queue worker را راه‌اندازی مجدد کنید تا job جدید RefreshUserProfilePhoto
# اجرا شود (اگر از PM2 استفاده می‌کنید):
pm2 restart tgads-queue

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

## رفع ۶ مشکل گزارش‌شده در نسخه ۲

### مشکل ۱ — ربات کندی (۲ ثانیه تاخیر)
دلایل احتمالی و راه‌حل‌ها:
- `TelegramBotClient::http()` timeout از ۱۵ ثانیه به **۸ ثانیه** و retry از ۲ به **۱** کاهش یافت.
- `SessionController::refreshProfilePhotoInBackground` که قبلا سینک به Telegram API صدا می‌زد و باعث ۲ ثانیه delay در اولین auth می‌شد، حالا به یک **queue Job** (`RefreshUserProfilePhoto`) dispatch می‌شود که به‌صورت async در worker `tgads-queue` اجرا می‌شود.
- **مهم:** PM2 worker را ری‌استارت کنید: `pm2 restart tgads-queue`

### مشکل ۲ — انیمیشن‌های ریز + حس لوکس + splash loader
- **Splash loader** (`layouts/app.blade.php`): یک splash screen برند با logo pulse + slide bar. بعد از ۱.۶ ثانیه (یا سریع‌تر اگر load تمام شد) fade out می‌شود. با sessionStorage یک‌بار در session کاربر نشان داده می‌شود.
- **Page transitions** (`app.css`): `.mini-content` با `ap-page-enter` ۳۲۰ms fade+slide با cubic-bezier springy.
- **Card hover lift**: `.card:hover` یک translate-up + shadow نرم.
- **Quick-action press**: scale(0.98) روی active.
- **Bottom nav**: indicator slide با spring + orb شناور برای create + glow + tooltip در desktop.
- **Locale toggle**: sliding thumb با springy cubic-bezier.

### مشکل ۳ — ارور ۵۰۰ احراز هویت
دو خطای واقعی در لاگ پیدا شد:

**خطای اول:** `App\Models\User::latestKycApplication must return a relationship instance, but "null" was returned`
- دلیل: متد `latestKycApplication` در `User` از نوع `?KycApplication` بود (نه یک relationship). وقتی Blade با `data_get($currentUser, 'latestKycApplication')` به آن دسترسی می‌یافت، Laravel آن را به‌عنوان relationship تفسیر می‌کرد و چون null بود، خطا می‌داد.
- **راه‌حل:** در `app/Models/User.php`، متد `latestKycApplication` به یک **HasOne relationship واقعی** تبدیل شد (با `orderByDesc('version')->limit(1)`). حالا `data_get` و property access هر دو null-safe هستند.

**خطای دوم:** `Class "App\Services\Setting" not found` در `PriceFeedService.php:90`
- دلیل: در `PriceFeedService` از `Setting::updateOrCreate` استفاده شده بود ولی `use App\Models\Setting;` فراموش شده بود.
- **راه‌حل:** use statement اضافه شد.

### مشکل ۴ — عکس پروفایل واقعی از تلگرام
- **`app/Jobs/RefreshUserProfilePhoto.php`** (NEW): یک queue Job که:
  - با `getUserProfilePhotos` آخرین عکس را می‌گیرد.
  - با `getFile` فایل را resolve می‌کند.
  - عکس را به `storage/app/avatars/{user_id}.{ext}` (private disk) **دانلود** می‌کند.
  - URL دائمی `https://your-domain/avatars/{user_id}.{ext}` را در `users.photo_url` ذخیره می‌کند.
  - عکس‌های قبلی با پسوندهای متفاوت را پاک می‌کند.
- **`app/Http/Controllers/MiniApp/AvatarController.php`** (NEW): یک controller public که عکس را از storage محلی serve می‌کند. بدون نیاز به `php artisan storage:link`.
- **`routes/web.php`**: route جدید `GET /avatars/{userId}/{ext}`.
- **`SessionController::refreshProfilePhotoInBackground`**: حالا فقط `RefreshUserProfilePhoto::dispatch($user->id)` را صدا می‌زند. response block نمی‌شود.
- **TTL ۶ ساعت** (`PROFILE_PHOTO_TTL_SECONDS=21600`): فقط هر ۶ ساعت یک‌بار عکس refresh می‌شود.
- اگه کاربر عکس پروفایل را در Telegram پنهان کرده باشد، Bot API چیزی برنمی‌گرداند و avatar فعلی (حرف اول نام) باقی می‌ماند.

### مشکل ۵ — دکمه FA/EN سوییچ حرفه‌ای
- در `layouts/app.blade.php` یک **segmented toggle** با دو گزینه FA و EN اضافه شد.
- در `app.css` کلاس‌های `.locale-toggle`, `.locale-toggle-track`, `.locale-toggle-thumb`, `.locale-toggle-option` با:
  - Track با gradient نرم + inset shadow.
  - Thumb با springy cubic-bezier `(0.34, 1.56, 0.64, 1)` که بین FA و EN slide می‌شود.
  - Active label با رنگ primary.
  - Hover و active states.
- در `app.js` رفتار click با fade-out ۲۰۰ms قبل از navigation + haptic feedback.
- بدون JS هم کار می‌کند (progressive enhancement با `<a href>`).

### مشکل ۶ — برند "Ads Platform" از env خوانده نشود
- در `resources/lang/fa/ui.php` و `resources/lang/en/ui.php` مقدار `brand` از `config('ads-platform.brand', 'Ads Platform')` خوانده می‌شود.
- در `config/ads-platform.php` مقدار از `env('ADS_PLATFORM_BRAND', 'Ads Platform')` خوانده می‌شود.
- در `.env.example` متغیر `ADS_PLATFORM_BRAND` با مقدار پیش‌فرض اضافه شد.
- حالا برای سفارشی‌سازی برند فقط کافی است در `.env` بنویسید:
  ```env
  ADS_PLATFORM_BRAND="نام برند شما"
  ```
- برند در topbar، title صفحه، splash loader، و پیام خوش‌آمد ربات نشان داده می‌شود.

## فایل‌های تغییریافته یا جدید (نسخه ۲ — شامل ۶ fix جدید)

| # | مسیر | نوع | مرتبط با |
|---|---|---|---|
| 1 | `.env.example` | M | متغیرهای brand + splash + price feed |
| 2 | `app/Console/Commands/RefreshExchangeRates.php` | **NEW** | `rates:refresh` artisan command |
| 3 | `app/Http/Controllers/Admin/CatalogController.php` | M | تغییر ۷ — update/destroy/toggle دسته + destroy کانال |
| 4 | `app/Http/Controllers/MiniApp/AvatarController.php` | **NEW v2** | مشکل ۴ — serve عکس پروفایل محلی |
| 5 | `app/Http/Controllers/MiniApp/CampaignController.php` | M | اضافی (سرچ کانال + min:1) |
| 6 | `app/Http/Controllers/MiniApp/KycController.php` | M | اضافی (card_holder_name + تطابق نام) |
| 7 | `app/Http/Controllers/MiniApp/SessionController.php` | M v2 | مشکل ۱ (async photo) + مشکل ۳ (structured 500) |
| 8 | `app/Jobs/RefreshUserProfilePhoto.php` | **NEW v2** | مشکل ۱ + مشکل ۴ — async download + local storage |
| 9 | `app/Models/User.php` | M v2 | مشکل ۳ — latestKycApplication به HasOne تبدیل شد |
| 10 | `app/Providers/AppServiceProvider.php` | M | throttle برای سرچ کانال |
| 11 | `app/Services/PriceFeedService.php` | M v2 | مشکل ۳ — فیکس use App\Models\Setting |
| 12 | `app/Services/PricingService.php` | M | تغییر ۵ |
| 13 | `app/Services/Telegram/TelegramBotClient.php` | M v2 | مشکل ۱ (timeout 8s + retry 1) + متدهای getChat/getUserPhotos/getFile |
| 14 | `app/Support/IranianIdentity.php` | M | namesLookSimilar + transliterate |
| 15 | `config/ads-platform.php` | M v2 | show_splash + splash_min_duration_ms + brand |
| 16 | `resources/css/app.css` | M v2 | مشکل ۲ (splash + page-enter + card hover) + مشکل ۵ (locale-toggle) |
| 17 | `resources/js/app.js` | M v2 | مشکل ۱ (fast-path auth) + مشکل ۲ (splash + locale fade) |
| 18 | `resources/lang/en/ui.php` | M v2 | مشکل ۶ — brand از config |
| 19 | `resources/lang/fa/ui.php` | M v2 | مشکل ۶ — brand از config |
| 20 | `resources/views/admin/channels/index.blade.php` | M | تغییر ۷ (fa/en bilingual + delete + toggle) |
| 21 | `resources/views/app/campaigns/create.blade.php` | M | اضافی (باکس سرچ کانال) |
| 22 | `resources/views/app/entry.blade.php` | M | تغییر ۳ (loader سبک) |
| 23 | `resources/views/app/home.blade.php` | M | تغییر ۶ (banner KYC با SLA) |
| 24 | `resources/views/app/identity/show.blade.php` | M v2 | مشکل ۳ — حذف data_get latestKycApplication |
| 25 | `resources/views/app/wallet/index.blade.php` | M | تغییر ۶ (banner KYC) |
| 26 | `resources/views/components/icon.blade.php` | M | آیکون‌های save/trash/close |
| 27 | `resources/views/layouts/app.blade.php` | M v2 | مشکل ۲ (splash) + مشکل ۵ (locale-toggle) + مشکل ۶ (brand) |
| 28 | `routes/console.php` | M | schedule rates:refresh |
| 29 | `routes/web.php` | M v2 | route avatar + سرچ کانال + admin category routes |

## مرور تغییرات نسخه ۱ (برای مرجع کامل)

### ۱. عکس پروفایل کاربر
- نسخه ۱: متدهای `getUserProfilePhotos`, `getFile`, `getLatestUserProfilePhotoUrl`.
- نسخه ۲: متدها در یک Job async پیچیده شدند و عکس در storage محلی ذخیره می‌شود (URL دائمی).

### ۲. منوی پایین لوکس
- HTML جدید با indicator + glow + orb شناور برای create.
- CSS با animation‌های springy، float، scale on press، haptic feedback، tooltip desktop.
- JS با indicator move، pointerdown scale، haptic ping.

### ۳. حذف گیت احراز هویت
- `entry.blade.php` به loader سبک pulse تبدیل شد.
- `app.js`: timeout از ۴ به ۱.۵ ثانیه کاهش + fast-path برای initData.

### ۴. رفع ارور ۵۰۰ احراز هویت
- `SessionController::store` با try/catch و structured JSON error.
- در نسخه ۲: دو خطای واقعی (`latestKycApplication` و `Setting` import) فیکس شد.

### ۵. قیمت دلار + ۴٪ + پشتیبان + GRAM/USD
- `PriceFeedService`: TGJU → Bonbast → Navasan → Exir → fallback برای USD/IRR؛ CoinGecko → CoinCap → Binance → fallback برای GRAM/USD.
- ۴٪ مارک‌آپ روی هر دو نرخ.
- هر ۵ دقیقه با `rates:refresh` schedule به‌روز می‌شود.

### ۶. banner KYC با SLA
- در صفحات خانه و کیف‌پول با "احراز سریع معمولاً ۶۰ دقیقه و نهایتاً ۲۴ ساعت".

### ۷. مدیریت دسته‌بندی ادمین
- نمایش fa/en، فرم ویرایش inline، دکمه حذف + toggle.

### اضافی — فیلدهای KYC + تطابق کارت با کد ملی
- فیلد جدید `card_holder_name` با منطق `namesLookSimilar`.

### اضافی — سرچ کانال با ID/لینک
- endpoint `/app/channels/search` + UI chip-based با enter/X.

### اضافی — nowpayments بدون احراز / zarinpay با احراز
- از قبل درست بود؛ اکنون در UI واضح نوشته شده.

## فیچرهای پیشنهادی برای آینده

### الف) بهبودهای مرتبط با تبلیغات
1. A/B testing تبلیغ — چند variant از متن/عکس و مقایسه CTR.
2. بازارچه خودکار CPM.
3. Schedule پیشرفشاده — چند بازه زمانی به‌جای یک شروع.
4. Multi-language campaign targeting.
5. Geographic targeting (وقتی Telegram Ads API پشتیبانی کند).

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
26. Two-factor authentication برای ادمین‌ها.
27. Rate-limited login attempts per user.
28. Session invalidation on password change.
29. Audit log tamper-evidence.
30. PCI-like data retention policy.

---

اگر سؤالی درباره هر کدام دارید یا می‌خواهید یکی از فیچرهای آینده را پیاده‌سازی کنم، بفرمایید.
