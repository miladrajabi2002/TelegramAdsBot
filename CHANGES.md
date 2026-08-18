# تغییرات اعمال‌شده روی TelegramAdsBot

این پوشه شامل تمام فایل‌های تغییریافته یا جدید است که بر اساس درخواست شما روی پروژه `TelegramAdsBot` اعمال شده‌اند.
برای نصب، کافی است هر فایل را در مسیر اصلی پروژه خود کپی کنید (ساختار پوشه‌ها در ZIP دقیقا مطابق با ریشه پروژه است).

> **مرجع کلون اصلی:** https://github.com/miladrajabi2002/TelegramAdsBot

## نحوه استفاده

1. فایل ZIP را دانلود و در یک پوشه موقت extract کنید.
2. تمام فایل‌ها را روی پروژه خودتان کپی کنید (پوشه‌بندی را حفظ کنید).
3. دستورات زیر را اجرا کنید تا تغییرات پایگاه داده و کش فعال شوند:

```bash
# اگر migration جدیدی اضافه شده باشد (در این نسخه مایگریشن جدیدی لازم نیست؛
# همه ستون‌های استفاده‌شده از قبل در جدول‌ها موجود هستند)
php artisan migrate

# کش‌های کانفیگ و view را پاک کنید
php artisan config:clear
php artisan view:clear
php artisan cache:clear

# اگر قبلا فایل‌های Vite را build کرده‌اید، دوباره build کنید
npm run build

# تنظیمات cron را برای schedule:run چک کنید (برای refresh خودکار قیمت‌ها)
# crontab -e
# * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1

# یک‌بار دستی قیمت‌ها را refresh کنید تا کاربران نرخ جدید را ببینند
php artisan rates:refresh
```

## پیشنهاد برای env.

در فایل `.env` خود این متغیرها را تنظیم کنید (یا به‌روی پیش‌فرض رها کنید):

```env
AUTOMATIC_EXCHANGE_RATE=true
PRICE_FEED_TTL_SECONDS=300
PRICE_MARKUP_PERCENT=4.0
TGJU_URL=https://call4.tgju.org/ajax.json
BONBAST_URL=https://bonbast.com/api/rates
NAVASAN_URL=https://navasan.net/api/v1/api.php
NAVASAN_API_KEY=

KYC_SLA_FAST_MINUTES=60
KYC_SLA_MAX_HOURS=24
PROFILE_PHOTO_TTL_SECONDS=21600
CHANNEL_SEARCH_PER_MINUTE=30
```

## فایل‌های تغییریافته یا جدید

| # | مسیر | نوع | مرتبط با |
|---|---|---|---|
| 1 | `.env.example` | M | تغییر ۵ (price feed) + تغییر ۱/۲/۶ |
| 2 | `app/Console/Commands/RefreshExchangeRates.php` | **NEW** | تغییر ۵ (rates:refresh artisan command) |
| 3 | `app/Http/Controllers/Admin/CatalogController.php` | M | تغییر ۷ (دسته‌بندی) — اضافه‌شدن `updateCategory`, `destroyCategory`, `toggleCategory`, `destroyChannel` |
| 4 | `app/Http/Controllers/MiniApp/CampaignController.php` | M | اضافی (سرچ کانال با ID) + نیازمندی B (لیست هدف غیرخالی) — متد جدید `searchChannel` و بازنویسی `storeTargets` + validation `min:1` |
| 5 | `app/Http/Controllers/MiniApp/KycController.php` | M | اضافی (فیلدهای KYC) — اضافه‌شدن `card_holder_name` و منطق تطابق نام با کد ملی |
| 6 | `app/Http/Controllers/MiniApp/SessionController.php` | M | تغییر ۱ (عکس پروفایل) + تغییر ۳ (ورود مستقیم) + تغییر ۴ (رفع ۵۰۰) |
| 7 | `app/Providers/AppServiceProvider.php` | M | throttle جدید برای endpoint سرچ کانال |
| 8 | `app/Services/PriceFeedService.php` | **NEW** | تغییر ۵ (TGJU + Bonbast + Navasan + Exir برای USD/IRR; CoinGecko + CoinCap + Binance برای GRAM/USD; +۴٪ markup + cache + default) |
| 9 | `app/Services/PricingService.php` | M | تغییر ۵ (به‌روزرسانی برای خواندن نرخ از PriceFeedService و ست‌کردن `rate_source` داینامیک) |
| 10 | `app/Services/Telegram/TelegramBotClient.php` | M | تغییر ۱ (متدهای `getUserProfilePhotos`, `getFile`, `fileDownloadUrl`, `getLatestUserProfilePhotoUrl`) + اضافی (متد `getChat` برای سرچ کانال) |
| 11 | `app/Support/IranianIdentity.php` | M | اضافی (متد `namesLookSimilar` و `transliterate` برای منطق تطابق نام صاحب کارت با کد ملی) |
| 12 | `config/ads-platform.php` | M | تغییر ۵/۶ (اضافه‌شدن کانفیگ price feed + SLA + profile photo TTL + channel search throttle) |
| 13 | `resources/css/app.css` | M | تغییر ۲ (منوی پایین لوکس مدرن با انیمیشن + channel-chip styles + glow + float) |
| 14 | `resources/js/app.js` | M | تغییر ۲ (انیمیشن indicator منو + haptic feedback) + تغییر ۳ (fast-path initData) + اضافی (سرچ کانال با enter/X/seed) |
| 15 | `resources/views/admin/channels/index.blade.php` | M | تغییر ۷ (نمایش fa/en برای دسته‌بندی + فرم ویرایش + دکمه حذف + toggle + نمایش fa/en برای فیلد category) |
| 16 | `resources/views/app/campaigns/create.blade.php` | M | اضافی (باکس سرچ کانال با placeholder، راهنما، نمایش chips) |
| 17 | `resources/views/app/entry.blade.php` | M | تغییر ۳ (حذف گیت، loader سبک با انیمیشن + fast-path auth) |
| 18 | `resources/views/app/home.blade.php` | M | تغییر ۶ (banner KYC با SLA + توضیح فیلدهای لازم) |
| 19 | `resources/views/app/identity/show.blade.php` | M | اضافی (فیلد جداگانه `card_holder_name` + راهنما برای مغایرت + توضیح SLA در checkbox) |
| 20 | `resources/views/app/wallet/index.blade.php` | M | تغییر ۶ (banner KYC با SLA در بالای wallet + نشانه‌گذاری "needs KYC" روی ZarinPay و "no KYC" روی NOWPayments) |
| 21 | `resources/views/components/icon.blade.php` | M | اضافه‌شدن آیکون‌های `save`, `trash`, `close` |
| 22 | `resources/views/layouts/app.blade.php` | M | تغییر ۲ (ساختار جدید HTML منوی پایین با indicator + orbs + tooltip) |
| 23 | `routes/console.php` | M | تغییر ۵ (schedule برای `rates:refresh` هر ۵ دقیقه) |
| 24 | `routes/web.php` | M | اضافی (route جدید `/app/channels/search`) + تغییر ۷ (routes جدید برای category delete/edit/toggle + channel delete) |

## فایل‌های تغییریافته با توضیح کوتاه

### ۱. عکس پروفایل کاربر در مینی‌اپ (تغییر ۱)
- **`app/Services/Telegram/TelegramBotClient.php`**: متدهای `getUserProfilePhotos`, `getFile`, `fileDownloadUrl`, `getLatestUserProfilePhotoUrl` اضافه شد. هر کدام با try/catch که در صورت شکست `null` برمی‌گرداند.
- **`app/Http/Controllers/MiniApp/SessionController.php`**: متد private `refreshProfilePhotoInBackground` که بعد از auth کامل شدن، یک‌بار برای هر ۶ ساعت (مطابق `ads-platform.profile_photo_ttl_seconds`) از Bot API عکس می‌گیرد و در `users.photo_url` ذخیره می‌کند. هرگز auth flow را بلاک نمی‌کند.
- اگر کاربر عکس پروفایل را در Telegram پنهان کرده باشد، Bot API چیزی برنمی‌گرداند و avatar فعلی (حرف اول نام) باقی می‌ماند.

### ۲. منوی پایین لوکس و مدرن (تغییر ۲)
- **`resources/views/layouts/app.blade.php`**: ساختار جدید با `<span class="mini-nav-indicator">` که بین تب‌ها حرکت می‌کند، `.mini-bottom-nav-glow` که یک glow نرم زیر نوار می‌اندازد، و `<span class="mini-nav-create-orb">` که برای دکمه Create تب مرکزی یک کره شناور با سایه آبی ایجاد می‌کند.
- **`resources/css/app.css`**: کلاس‌های `.mini-bottom-nav`, `.mini-bottom-nav-inner`, `.mini-nav-item`, `.mini-nav-icon`, `.mini-nav-label`, `.mini-nav-create`, `.mini-nav-create-orb`, `.mini-nav-indicator`, `.mini-bottom-nav-glow` با animation‌های springy (`cubic-bezier(0.34, 1.56, 0.64, 1)`), float آبی دکمه create با keyframe `ap-nav-create-float`, scale + lift روی hover، پشتیبانی `prefers-reduced-motion`، tooltip در desktop.
- **`resources/js/app.js`**: در init، `bottom-nav` را پیدا می‌کند، indicator را به تب active منتقل می‌کند، روی pointerdown `is-pressed` اضافه می‌کند، با `Telegram.WebApp.HapticFeedback.impactOccurred('light')` حس لمسی می‌دهد.

### ۳. حذف گیت احراز هویت (تغییر ۳)
- **`resources/views/app/entry.blade.php`**: جایگزینی با یک loader سبک با انیمیشن pulse + نوار لغزشی. هیچ panel تاریک "connecting securely" دیگری نیست. کاربر این صفحه را فقط یک لحظه می‌بیند و سپس به `/app/home` redirect می‌شود.
- **`resources/js/app.js`**: کاهش timeout از ۴ ثانیه به ۱.۵ ثانیه. اضافه‌شدن fast-path: اگر `telegram.initData` در لحظه load غیر خالی باشد، مستقیما (بدون صبر برای callback) auth را fire می‌کند.
- **`app/Http/Controllers/MiniApp/SessionController.php`**: اگر کاربر از قبل auth شده باشد، در همان GET به `/app` به `/app/home` redirect می‌شود (بدون نمایش entry).

### ۴. رفع ارور ۵۰۰ احراز هویت (تغییر ۴)
دلایل محتمل ارور ۵۰۰:
- مایگریشن `magic_token` اجرا نشده باشد و INSERT به ستون ناموجود برخورد کند.
- session driver در دسترس نباشد و `session()->regenerate()` خطا بدهد.
- DB unreachable باشد.
راه حل:
- **`app/Http/Controllers/MiniApp/SessionController.php`**:
  - `regenerateSessionSafely()` در `try/catch` قرار داده شده — اگر regenerate شکست بخورد فقط warning لاگ می‌شود ولی کاربر همچنان لاگین می‌ماند.
  - هر `User::save()` و `User::where('magic_token')->first()` در `try/catch` پیچیده شده.
  - در صورت خطا به جای abort(500) با متن خام، یک JsonResponse با ساختار `{error, message, retry_after_seconds}` برمی‌گردد تا JS بتواند دکمه Retry نشان دهد.
  - خطاها در `storage/logs/laravel.log` با context ذخیره می‌شوند.

### ۵. قیمت دلار + ۴٪ + پشتیبان + GRAM/USD (تغییر ۵)
- **`app/Services/PriceFeedService.php`** (NEW):
  - USD/IRR با ترتیب: TGJU → Bonbast → Navasan → Exir → fallback static.
  - GRAM/USD با ترتیب: CoinGecko (TON) → CoinCap (TON) → Binance TONUSDT → fallback static.
  - Cache با TTL ۵ دقیقه (USD) و ۱۰ دقیقه (GRAM) تا API‌ها شلوغ نشوند.
  - markup `+۴٪` (مطابق `PRICE_MARKUP_PERCENT=4.0`) به هر دو نرخ اعمال می‌شود.
  - متد `persistToSettings()` که در `settings` table با کلیدهای `usd_to_irr` و `gram_to_usd` (با `is_public=true`) ذخیره می‌کند.
- **`app/Services/PricingService.php`**: 
  - اگر `automatic_exchange_rate=true` باشد، نرخ‌ها از `PriceFeedService::currentRates()` خوانده می‌شوند.
  - `rate_source` داینامیک: `live;usd:live;gram:live;+4%` یا `admin_settings` در حالت manual.
- **`app/Console/Commands/RefreshExchangeRates.php`** (NEW): دستور `php artisan rates:refresh` که با `--force` cache را پاک و دوباره fetch می‌کند، و با `--show` فقط نمایش می‌دهد.
- **`routes/console.php`**: schedule هر ۵ دقیقه برای `rates:refresh` (با `withoutOverlapping`).
- **`config/ads-platform.php`**: کلیدهای جدید `tgju_url`, `bonbast_url`, `navasan_url`, `navasan_api_key`, `price_markup_percent`, `price_feed_ttl_seconds`, `automatic_exchange_rate`.
- **`.env.example`**: متغیرهای جدید بالا با مقادیر پیش‌فرض.

### ۶. banner KYC با SLA "۱ ساعت / ۲۴ ساعت" (تغییر ۶)
- **`resources/views/app/wallet/index.blade.php`**: 
  - اگر `canRial=false` یک banner زرد در بالای صفحه با "برای افزایش موجودی ریالی، احراز هویت لازم است" و زیرنویس "احراز سریع معمولاً ۶۰ دقیقه و نهایتاً ۲۴ ساعت طول می‌کشد".
  - روی کارت ZarinPay عنوان "نیازمند احراز هویت" و روی NOWPayments "بدون نیاز به احراز هویت" اضافه شد.
  - در خود notice داخلی ZarinPay هم SLA ذکر می‌شود.
- **`resources/views/app/home.blade.php`**: notice KYC در صفحه خانه با SLA و توضیح فیلدها.
- **`resources/views/app/identity/show.blade.php`**: در consent checkbox ذکر می‌شود که "می‌دانم که احراز سریع معمولاً ۱ ساعت و نهایتاً ۲۴ ساعت طول می‌کشد."

### ۷. مدیریت دسته‌بندی ادمین (تغییر ۷)
- **`app/Http/Controllers/Admin/CatalogController.php`** متدهای جدید:
  - `updateCategory(Request, TargetCategory, AuditLogger)` — ویرایش title_fa/en, description_fa/en, icon, sort_order, is_active.
  - `destroyCategory(TargetCategory, AuditLogger)` — حذف دسته (pivot cascade می‌کند، campaign_targets دست‌نخورده باقی می‌مانند). رد می‌کند اگر فقط یک دسته فعال بماند.
  - `toggleCategory(TargetCategory, AuditLogger)` — switch is_active.
  - `destroyChannel(SuggestedChannel, AuditLogger)` — حذف کانال.
  - `storeCategory` حالا شامل فیلدهای `icon` و `sort_order` نیز هست.
- **`routes/web.php`**:
  - `PUT /admin/channel-categories/{category}` → `admin.channels.categories.update`
  - `POST /admin/channel-categories/{category}/toggle` → `admin.channels.categories.toggle`
  - `DELETE /admin/channel-categories/{category}` → `admin.channels.categories.destroy`
  - `DELETE /admin/channels/{channel}` → `admin.channels.destroy`
- **`resources/views/admin/channels/index.blade.php`**: 
  - هر دسته هم `title_fa` و هم `title_en` نمایش داده می‌شود (bilingual).
  - فرم ویرایش inline (با toggle JS) برای title_fa/en, description_fa/en, icon, sort_order, is_active.
  - دکمه‌های ویرایش / toggle / حذف در هر ردیف.
  - فرم ساخت دسته جدید شامل فیلدهای description_fa/en, icon, sort_order.
  - دکمه حذف در فهرست کانال‌ها در هر ردیف اضافه شد.
  - در select گروه‌بندی channel_create، فارسی و انگلیسی هر دو نمایش داده می‌شود.

### اضافی — فیلدهای KYC و تطابق کارت با کد ملی
- **`app/Http/Controllers/MiniApp/KycController.php`**: 
  - فیلد جدید `card_holder_name` در validation و در `FundingCard::holder_name_encrypted` ذخیره می‌شود.
  - متد `IranianIdentity::namesLookSimilar` برای مقایسه `card_holder_name` با `legal_name`.
  - در صورت مغایرت: KYC application به حالت `Draft` می‌ماند و `kycService->submit()` فراخوانی نمی‌شود. کاربر در `KycLevel::Base` باقی می‌ماند.
  - پیام warning به کاربر نمایش داده می‌شود: "نام صاحب کارت با نام اعلامی شما مطابقت ندارد... حساب در سطح پایه باقی می‌ماند."
  - در `funding_cards.verification_result` علت `cardholder_name_mismatch` ذخیره می‌شود.
- **`app/Support/IranianIdentity.php`**: متدهای `namesLookSimilar` (مقایسه تطبیقی با transliteration فارسی↔لاتین) و `transliterate`.
- **`resources/views/app/identity/show.blade.php`**: فیلد جداگانه `card_holder_name` با راهنما، توضیح "کارت باید متعلق به همان کد ملی و همان نام صاحب حساب باشد"، و توضیح "در صورت مغایرت، درخواست بدون ورود به صف بررسی نگه داشته می‌شود."
- فیلدهای فعلی مطابق با درخواست:
  - شماره تلفن → از قبل verify شده (`users.phone`, readonly در فرم).
  - تصویر کارت ملی خالی → `national_id_image` (نسخه front، بدون کاور).
  - شخص+کارت ملی → `selfie_with_id_image`.
  - نام صاحب حساب → `card_holder_name` (جدید، دقیقاً همان نام روی کارت).
  - شماره کارتی که می‌خواهد واریز کند → `card_number`.

### اضافی — سرچ کانال یا بات با آیدی و لینک
- **`app/Http/Controllers/MiniApp/CampaignController.php`**:
  - متد جدید `searchChannel(Request, TelegramBotClient)` که با `?q=@username|https://t.me/...|-1001234567890` کار می‌کند. اول `suggested_channels` table را چک می‌کند (برای avatar و members snapshot محلی)، سپس `TelegramBotClient::getChat` را فراخوانی می‌کند. avatar از `getFile` استخراج می‌شود.
  - پاسخ JSON: `{id, username, title, avatar, members, source}`.
- **`routes/web.php`**: `GET /app/channels/search` با throttle `miniapp-channel-search` (۳۰ req/min/user).
- **`app/Providers/AppServiceProvider.php`**: throttle جدید.
- **`resources/js/app.js`**: 
  - component `[data-channel-search]` با input, results, hidden container.
  - Enter یا comma → lookup → chip با avatar + title + username + دکمه ×.
  - Backspace در input خالی → آخرین chip را پاک می‌کند.
  - Paste چند خطی → هر خط را جدا lookup می‌کند.
  - وقتی JS بارگذاری می‌شود، اگر hidden inputs موجود باشند (مثلا برای صفحه edit) آنها را به chips تبدیل می‌کند.
- **`resources/views/app/campaigns/create.blade.php`**: باکس سرچ جدید در بالای wizard step 3.
- **`app/Http/Controllers/MiniApp/CampaignController.php::storeTargets`**: پذیرفتن `target_channel_ids` به‌عنوان integer id یا string username. اگر در کاتالوگ محلی موجود نباشد، به‌عنوان manual channel ذخیره می‌شود (که operator بعداً بررسی می‌کند).
- **validation**: `target_channel_ids` از `nullable` به `required|min:1` تغییر کرد — باید حداقل یک کانال انتخاب شود.

### اضافی — nowpayments بدون احراز / zarinpay با احراز
این از قبل در `PaymentController::topUpWithZarinPay` و `PaymentService::createZarinPayIntent` با `assertRialKyc` اجرا می‌شد. `topUpWithNowPayments` و `payOrderWithNowPayments` هرگز `assertRialKyc` را فراخوانی نمی‌کردند. در UI اکنون به‌طور واضح روی کارت ZarinPay "نیازمند احراز هویت" و روی NOWPayments "بدون نیاز به احراز هویت" نوشته می‌شود. نیازی به تغییر کد نبود — فقط نمایش UI بهبود یافت.

## فیچرهای پیشنهادی برای آینده (بخش ۸)

این‌ها فیچرهایی هستند که با توجه به ساختار فعلی کد قابل اضافه‌شدن هستند ولی در این نسخه پیاده‌سازی نشده‌اند:

### الف) بهبودهای مرتبط با تبلیغات
1. **A/B testing تبلیغ** — چند variant از متن/عکس تبلیغ و مقایسه CTR.
2. **بازارچه خودکار CPM** — پیشنهاد CPM با توجه به عرضه/تقاضا لحظه‌ای.
3. **Schedule پیشرفته** — چند بازه زمانی (مثلا فقط ساعت ۲۰-۲۲) به‌جای یک شروع.
4. **Multi-language campaign targeting** — تبلیغ فارسی فقط به کانال‌های فارسی، انگلیسی فقط به انگلیسی.
5. **Geographic targeting** — وقتی Telegram Ads API از آن پشتیبانی کرد، هدف‌گذاری بر اساس کشور/شهر.

### ب) بهبودهای مالی
6. **Withdrawal رمزارزی** — خروج موجودی به کیف پول TON/USDT.
7. **Referral program** — دعوت دوستان و دریافت درصد از سفارش‌های آن‌ها.
8. **گزارش‌های مالی PDF** — خلاصه ماهانه برای کاربران به‌صورت PDF.
9. **Multi-currency wallet** — موجودی به‌صورت همزمان IRR, USDT, TON نمایش داده شود و conversion خودکار هنگام پرداخت.
10. **محدودیت‌های هوشمند کارت** — اگر یک کارت بانکی در ۲۴ ساعت بیش از N بار استفاده شد، بطور موقت قفل شود.

### ج) بهبودهای اپراتور/ادمین
11. **Bulk channel verification** — یک‌بارسازی وضعیت کانال‌ها با getChat و گرفتن count عضو واقعی.
12. **Templates کمپین** — ادمین قالب‌های آماده متن تبلیغ بسازد و کاربر انتخاب کند.
13. **Auto-approve KYC برای ربات‌های پاسخگو** — اگر شماره کارت از طریق API بانکی متعلق به همان کد ملی بود، KYC خودکار تأیید شود (نیازمند همکاری بانک یا سرویس third-party).
14. **Dashboard اپراتور** — صف کار اپراتورها با اولویت کمپین‌های active.
15. **Audit log replay** — امکان بازپخش تغییرات یک سفارش از ابتدا.

### د) بهبودهای UX و فنی
16. **Dark mode** — حالت تاریک برای Mini App.
17. **Offline-first PWA** — Cache aggressive برای view‌ها و در دسترس بودن حتی بدون اینترنت.
18. **Push notifications** — اطلاع‌رسانی فوری به کاربر وقتی سفارش تأیید یا رد شد (با Telegram API).
19. **i18n با plurals** — پشتیبانی از جمع و مفرد برای انگلیسی.
20. **WebSocket dashboard** — به‌روزرسانی زنده داشبورد ادمین بدون refresh.

### هـ) رشد و کسب‌وکار
21. **Coupon / Discount codes** — کد تخفیف برای کاربران دعوت‌شده.
22. **Tiered pricing** — کاربران با حجم بالاتر نرخ CPM کمتری بپردازند.
23. **Self-serve monthly subscriptions** — اشتراک ماهانه با بودجه ثابت.
24. **Telegram Stars support** — وقتی Telegram Stars روی پلتفرم Ads فعال شد، پذیرش ستاره به‌جای/در کنار GRAM.
25. **Analytics dashboard کاربر** — نمودار عملکرد چند کمپین اخیر.

### و) امنیت
26. **Two-factor authentication** — برای ادمین‌ها.
27. **Rate-limited login attempts per user** — جلوگیری از brute-force روی magic_token.
28. **Session invalidation on password change** — وقتی ادمین رمز خود را عوض کرد، session‌های قبلی باطل شوند.
29. **Audit log tamper-evidence** — hash زنجیره‌ای برای audit log تا تغییر دستی قابل تشخیص باشد.
30. **PCI-like data retention policy** — حذف خودکار مدارک KYC بعد از N سال (الان ۵ سال مطابق `kyc_retention_days`).

---

اگر سؤالی درباره هر کدام دارید یا می‌خواهید یکی از فیچرهای آینده را پیاده‌سازی کنم، بفرمایید.
