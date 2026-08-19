# TelegramAdsBot — تغییرات نسخه ششم (Patch)

این پوشه شامل 4 فایل تغییر یافته برای حل مشکل عکس پروفایل در پنل ادمین
هست.

## مشکل: عکس پروفایل در مینی‌اپ میاد ولی در پنل ادمین نمیاد

**علت:** در نسخه قبلی، `AvatarController` به‌جای دانلود عکس، یک 302
redirect به Telegram CDN URL (شامل bot token) می‌داد. این URL:

1. **توکن بات رو در HTML نشون می‌داد** — یک مشکل امنیتی جدی.
2. **گاهی از مرورگر دسکتاپ (پنل ادمین) قابل دسترس نبود** — به دلیل:
   - محدودیت‌های شبکه روی `api.telegram.org`
   - CORS/mixed-content issues
   - فیلتر کردن در برخی شبکه‌ها

در مینی‌اپ این کار می‌کرد چون WebView تلگرام به‌طور پیش‌فرض می‌تونه به
`api.telegram.org` وصل بشه. ولی در پنل ادمین (مرورگر دسکتاپ معمولی)،
این URL گاهی load نمی‌شد و عکس نمایش داده نمی‌شد.

## راه‌حل: Server-side streaming + caching

### 1) `AvatarController` بازنویسی شد

به‌جای redirect، عکس رو به‌صورت **streamed response** برمی‌گردونه:

- `AvatarController::show()` عکس رو از Telegram API (از طریق متد جدید
  `downloadLatestUserProfilePhoto` در `TelegramBotClient`) دانلود می‌کنه.
- عکس به‌صورت `Response` با `Content-Type` مناسب و cache headers (5
  دقیقه) برگردانده می‌شه.
- عکس در `Cache` (پیش‌فرض database) به مدت 5 دقیقه ذخیره می‌شه تا
  درخواست‌های مکرر به Telegram API نرن.

اینطوری:
- **مرورگر فقط با سرور ما حرف می‌زنه** (همان origin) — نه Telegram CDN.
- **توکن بات هیچ‌وقت در HTML نشون داده نمی‌شه.**
- **CORS/mixed-content مشکلی نیست.**
- **هم در مینی‌اپ و هم در پنل ادمین کار می‌کنه.**

### 2) متد جدید `downloadLatestUserProfilePhoto` در `TelegramBotClient`

این متد:
1. `getUserProfilePhotos` رو صدا می‌زنه.
2. `getFile` رو صدا می‌زنه.
3. URL دانلود رو می‌سازه.
4. عکس رو با `Http::get` دانلود می‌کنه.
5. `bytes` و `mime` رو برمی‌گردونه (یا `null` اگه کاربر عکس نداشته
   باشه یا API در دسترس نباشه).

### 3) `AppServiceProvider::avatarUrl` ساده شد

قبلاً یک "fast path" داشت که اگه `photo_url` تازه باشه، مستقیم به Telegram
CDN برمی‌گشت. حالا **همیشه** به route `avatar.show` برمی‌گرده که عکس رو
stream می‌کنه. این ساده‌سازی:
- توکن بات رو از HTML حذف می‌کنه.
- هم در مینی‌اپ و هم در پنل ادمین رفتار یکسان می‌سازه.

### 4) `SessionController::refreshProfilePhotoInBackground` no-op شد

قبلاً در هر لاگین، Telegram API صدا زده می‌شد تا `photo_url` در دیتابیس
به‌روزرسانی بشه. حالا که `AvatarController` خودش به‌صورت on-demand عکس
رو دانلود و cache می‌کنه، این کار اضافی هست و فقط لاگین رو کند می‌کنه.
متد به‌عنوان no-op نگه داشته شد (برای backward-compat با دو caller).

## فایل‌های تغییر یافته

| فایل | تغییر |
|------|-------|
| `app/Http/Controllers/MiniApp/AvatarController.php` | بازنویسی کامل: stream به‌جای redirect + caching |
| `app/Services/Telegram/TelegramBotClient.php` | اضافه شدن متد `downloadLatestUserProfilePhoto()` |
| `app/Providers/AppServiceProvider.php` | ساده‌سازی `avatarUrl()` — همیشه از route استفاده می‌کنه |
| `app/Http/Controllers/MiniApp/SessionController.php` | `refreshProfilePhotoInBackground` به no-op تبدیل شد |

## نحوه‌ی استقرار

این 4 فایل رو روی پروژه‌ی فعلی‌ت کپی کن. سپس:

```bash
php artisan view:clear
php artisan config:clear
php artisan cache:clear
```

## تست

1. به پنل ادمین برو (`/admin/users` یا `/admin/kyc`).
2. عکس‌های پروفایل کاربرها باید نشون داده بشن.
3. در DevTools → Network، ببین که درخواست‌ها به `/avatars/{id}` (روی
   دامنه‌ی خودت) می‌رن، نه به `api.telegram.org`.
4. اگه یک کاربر عکس نداشته باشه، حرف اول اسمش نشون داده می‌شه
   (به‌جای علامت عکس شکسته).

## نکات

- **کارایی:** اولین درخواست برای هر کاربر ~1-2 ثانیه طول می‌کشه (دانلود
  از Telegram). درخواست‌های بعدی در 5 دقیقه از cache سریع برمی‌گرده.
- **Cache:** اگه `CACHE_STORE=database` هست (پیش‌فرض)، جدول `cache` در
  دیتابیس استفاده می‌شه. اگه `redis` یا `file` استفاده می‌کنی، همون
  کار رو می‌کنه.
- **Rate limit:** route `/avatars/{id}` با throttle `avatars` (30 req/min/IP)
  محدود شده. اگه صفحه‌ای تعداد زیادی avatar داره، ممکنه به این limit
  برسی. در این صورت، cache 5 دقیقه‌ای کمک می‌کنه.
- **توکن بات:** حالا در HTML هیچ‌جا نشون داده نمی‌شه. می‌تونی با View
  Source در مرورگر چک کنی.
