# TelegramAdsBot — تغییرات نسخه هفتم (Avatar Redirect Patch)

این پوشه شامل ۷ فایل تغییر یافته برای حل نهایی مشکل عکس پروفایل در پنل ادمین
هست.

## مشکل: عکس پروفایل در مینی‌اپ میاد ولی در پنل ادمین نمیاد (نسخه ۲)

در نسخه قبلی (Patch 6)، `AvatarController` به‌جای redirect، عکس رو به‌صورت
**streamed response** (دانلود byte-by-byte روی سرور و برگرداندن byte‌ها به
مرورگر) برمی‌گردوند. این رویکرد برای مینی‌اپ (یک کاربر، یک avatar) کار می‌کرد
ولی در پنل ادمین با مشکلات اساسی روبرو می‌شد:

1. **تعداد زیاد Telegram API call در هر بار رندر صفحه:** هر صفحه‌ی admin
   users list حدود ۲۵ avatar رو هم‌زمان رندر می‌کرد. هر avatar = ۳ call به
   Telegram (getUserProfilePhotos + getFile + HTTP download) = ۷۵+ request
   به api.telegram.org در یک بار لود صفحه.

2. **Rate limit Telegram:** api.telegram.org با تعداد زیاد call در زمان کوتاه
   مشکل داشت و timeout می‌زد.

3. **Throttle محلی:** `/avatars/{id}` با throttle `avatars` (30 req/min/IP)
   محدود شده بود. با ۲۵ avatar هم‌زمان، به محض لود صفحه به limit می‌رسیدیم و
   بقیه avatarها 429 برمی‌گردوندن و fallback (حرف اول اسم) نشون داده می‌شد.

4. **Cache ۵ دقیقه‌ای کمک نمی‌کرد:** وقتی ۲۵ کاربر **مختلف** برای بار اول
   رندر می‌شدن، cache خالی بود و همه باید از Telegram دانلود می‌شدن.

## راه‌حل: 302 redirect به Telegram CDN URL (بدون دانلود)

### 1) `AvatarController` بازنویسی شد (دوباره)

به‌جای download + stream، حالا:

- `getUserProfilePhotos` و `getFile` رو صدا می‌زنه تا `file_path` رو بگیره.
- URL دانلود رو می‌سازه: `https://api.telegram.org/file/bot{TOKEN}/{file_path}`
- این URL رو در cache (پیش‌فرض database) به مدت **~50 دقیقه** ذخیره می‌کنه
  (Telegram file_path حدود ۱ ساعت معتبره).
- پاسخ رو به‌صورت **302 redirect** به این URL برمی‌گردونه.

مزایا:
- **هیچ byte‌ای روی سرور دانلود نمی‌شه** — فقط URL گرفته می‌شه.
- **Browser مستقیماً از Telegram CDN دانلود می‌کنه** (پارالل، بدون throttle
  روی سرور ما).
- **Cache ۵۰ دقیقه‌ای** یعنی برای هر کاربر فقط یک بار در ساعت به Telegram
  API call می‌شه.
- **حالت "no photo" هم cache می‌شه** (۵ دقیقه) تا برای کاربرانی که عکس
  ندارن، هی به Telegram API نزنیم.

### 2) `AppServiceProvider::avatarUrl` fast path برگردونده شد

قبلاً همیشه `route('avatar.show')` برمی‌گردوند. حالا دوباره **fast path** داره:

- اگه `photo_url` روی کاربر تازه باشه (Telegram CDN URL و کمتر از ۳۰ دقیقه
  از updated_at گذشته)، مستقیم همون URL برمی‌گردونه.
- در غیر این صورت، `route('avatar.show')` برمی‌گردونه که به‌صورت on-demand URL
  رو از Telegram می‌گیره و redirect می‌کنه.

این یعنی مینی‌اپ وقتی کاربر login می‌کنه و `photo_url` تازه می‌شه، دیگه
هیچ request اضافه‌ای به `/avatars/{id}` نمی‌زنه — مستقیم از Telegram CDN
لود می‌کنه.

### 3) `SessionController::refreshProfilePhotoInBackground` دوباره فعال شد

قبلاً no-op شده بود (چون AvatarController به‌صورت on-demand دانلود می‌کرد).
حالا دوباره `$user->refreshTelegramPhotoUrl($bot)` رو صدا می‌زنه تا
`photo_url` در login تازه بشه. این fast path رو برای مینی‌اپ فعال نگه
می‌داره.

### 4) Endpoint جدید برای refresh دستی عکس توسط ادمین

POST `/admin/users/{user}/refresh-photo` اضافه شد. وقتی ادمین می‌بینه
avatar یک کاربر نشون داده نمی‌شه (مثلاً کاربر مدت‌هاست login نکرده و
`photo_url` اش منقضی شده)، می‌تونه با یک کلیک روی دکمه‌ی "تازه‌سازی عکس"
در صفحه‌ی `/admin/users/{user}` آدرس رو از Telegram دوباره بگیره.

این endpoint:
- `refreshTelegramPhotoUrl()` رو صدا می‌زنه (یعنی getUserProfilePhotos +
  getFile + ذخیره در `users.photo_url`).
- Cache آواتار اون کاربر رو invalidate می‌کنه تا رندر بعدی از URL تازه
  استفاده کنه.
- در audit log ثبت می‌شه (`user.photo_refreshed`).

### 5) `SecurityHeaders` برای `/avatars/*`

`Referrer-Policy: no-referrer` روی response‌های `/avatars/*` ست می‌شه. این
یعنی وقتی مرورگر به api.telegram.org redirect می‌شه، header `Referer` خالی
ارسال می‌کنه و URL سرور ما (که شامل path با bot token نیست، فقط `/avatars/{id}`)
نشون داده نمی‌شه. (توکن بات در URL تلگرام هست، نه در URL سرور ما.)

## ملاحظات امنیتی

این رویکرد، URL تلگرام (شامل bot token) رو در redirect نشون می‌ده. این یعنی:

- **در DevTools → Network → Location header:** توکن بات قابل دیدنه.
- **در image src بعد از redirect:** قابل دیدنه.

این یک trade-off هست که کاربر صریحاً قبول کرده (می‌خواست بدون دانلود باشه).
برای کاهش خطر:

1. **Rate limit:** `/avatars/{id}` با throttle `avatars` (30 req/min/IP) محدود
   شده. یک IP نمی‌تونه brute-force کنه.
2. **Cache:** URL ۵۰ دقیقه cache می‌شه. توکن نشون داده شده تا ۱ ساعت معتبره
   (TTL Telegram)، پس cache کردنش مشکلی ایجاد نمی‌کنه.
3. **Referrer-Policy: no-referrer:** روی `/avatars/*` ست می‌شه تا URL تو
   Referer header نشون داده نشه.
4. **Routing عمومی:** `/avatars/{id}` عمومیه (no auth) چون `<img src>` همیشه
   session cookie رو forward نمی‌کنه. ولی در عمل، فقط صفحات authenticate
   شده (admin panel یا مینی‌اپ) این `<img>` tag‌ها رو render می‌کنن.
5. **Rotation:** اگه روزی توکن لو بره، کافیه در @BotFather توکن رو revoke
   کنی و `TELEGRAM_BOT_TOKEN` جدید رو در `.env` ست کنی.

## فایل‌های تغییر یافته

| فایل | تغییر |
|------|-------|
| `app/Http/Controllers/MiniApp/AvatarController.php` | بازنویسی کامل: 302 redirect به Telegram CDN URL به‌جای byte streaming. URL caching (50 min) + "no photo" caching (5 min). |
| `app/Providers/AppServiceProvider.php` | `avatarUrl()` دوباره fast path داره: اگه `photo_url` تازه باشه، مستقیم برمی‌گرده؛ وگرنه به route می‌ره. |
| `app/Http/Controllers/MiniApp/SessionController.php` | `refreshProfilePhotoInBackground()` دوباره فعال شد تا در login، `photo_url` تازه بشه. |
| `app/Http/Controllers/Admin/UserController.php` | متد جدید `refreshPhoto()` برای refresh دستی توسط ادمین. |
| `app/Http/Middleware/SecurityHeaders.php` | `Referrer-Policy: no-referrer` برای `/avatars/*`. |
| `routes/web.php` | Route جدید: POST `/admin/users/{user}/refresh-photo`. |
| `resources/views/admin/users/show.blade.php` | دکمه‌ی "تازه‌سازی عکس" در hero section کاربر اضافه شد. |

## نحوه‌ی استقرار

این ۷ فایل رو روی پروژه‌ی فعلی‌ت کپی کن. سپس:

```bash
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

(اختیاری) اگه می‌خوای cache رو برای همه‌ی کاربران invalidate کنی تا آواتارها
با URL تازه رندر بشن:

```bash
php artisan tinker
>>> Cache::getFacadeRoot()->flush();
```

> **هشدار:** این کار کل cache رو پاک می‌کنه (شامل cache‌های دیگه). در production
> با احتیاط استفاده کن.

## تست

1. به پنل ادمین برو (`/admin/users`).
2. عکس‌های پروفایل کاربرها باید نشون داده بشن. اگه نشد:
   - در DevTools → Network، چک کن که request‌های `/avatars/{id}` برمی‌گردونن
     `302` با Location header به `https://api.telegram.org/file/bot...`.
   - اگه 404 برمی‌گردونه، یا کاربر عکس پروفایل نداره، یا `TELEGRAM_BOT_TOKEN`
     ست نشده.
3. در `/admin/users/{id}`، دکمه‌ی "تازه‌سازی عکس" رو امتحان کن. باید flash
   success نشون بده و avatar تازه بشه.
4. در مینی‌اپ، login کن و چک کن که avatar در topbar درست نشون داده بشه.

## نکات

- **کارایی:** اولین request برای هر کاربر ~0.5-1 ثانیه طول می‌کشه (دو API call
  به Telegram). بعدش، cache ۵۰ دقیقه‌ای سریع برمی‌گرده و browser مستقیم از
  Telegram CDN لود می‌کنه.
- **Cache store:** اگه `CACHE_STORE=database` هست (پیش‌فرض)، جدول `cache` در
  دیتابیس استفاده می‌شه. اگه `redis` یا `file`، همون کار رو می‌کنه.
- **Bot token leak:** توکن در URL redirect قابل دیدنه. این رو قبول کردی به
  جای اینکه byte‌ها رو روی سرور دانلود کنی. برای کاهش خطر، throttle، cache،
  و Referrer-Policy ست شده. اگه روزی نیاز به امنیت بیشتر بود، می‌تونی
  برگردی به byte streaming (فقط AvatarController رو عوض کن).
- **`TelegramBotClient::downloadLatestUserProfilePhoto`** هنوز در کد هست (برای
  backward compat) ولی دیگه توسط AvatarController استفاده نمی‌شه.
