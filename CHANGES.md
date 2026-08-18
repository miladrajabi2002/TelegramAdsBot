# TelegramAdsBot — تغییرات اعمال شده

این پوشه شامل تمام فایل‌های تغییر یافته‌ی پروژه‌ی TelegramAdsBot است.
ساختار فولدرها دقیقاً مطابق با پروژه‌ی اصلی حفظ شده، پس می‌توانید
فقط فایل‌های این پوشه را روی پروژه‌ی خود کپی کنید.

## 1) عکس پروفایل (لینک مستقیم Telegram)
- فایل‌های جدید/تغییر یافته:
  - `app/Models/User.php` — اضافه شدن متد `refreshTelegramPhotoUrl()`
    که با یک فراخوانی `getUserProfilePhotos` + `getFile` لینک مستقیم
    CDN تلگرام را می‌گیرد و در `users.photo_url` ذخیره می‌کند. نیازی به
    دانلود فایل و ذخیره روی دیسک نیست.
  - `app/Http/Controllers/MiniApp/SessionController.php` — در هر لاگین
    فقط `refreshTelegramPhotoUrl()` را صدا می‌زند. عکس دیگر دانلود
    نمی‌شود.
  - `app/Http/Controllers/MiniApp/AvatarController.php` — کاملاً بازنویسی
    شد. به جای ذخیره‌ی عکس روی دیسک، یک 302 redirect به لینک Telegram
    CDN می‌زند. backward-compatible با `<img src="/avatars/{id}">` قدیمی.
- فایل حذف شده:
  - `app/Jobs/RefreshUserProfilePhoto.php` — dead code بود، حذف شد.

## 2) نمایش عکس‌ها در پنل ادمین و مینی‌اپ
تمام `<img>` های عکس پروفایل در این ویوها با `onerror` fallback
به‌روزرسانی شدند تا اگر لینک Telegram منقضی شد یا کاربر عکس نداشت،
به‌جای علامت عکس شکسته، حرف اول اسم نمایش داده شود:
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/users/show.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `resources/views/admin/kyc/index.blade.php`
- `resources/views/admin/kyc/show.blade.php`
- `resources/views/app/account.blade.php`

## 3) ساده‌سازی تایید احراز هویت (KYC)
- حذف لیبل «KYC v1» و ستون «نسخه» در لیست احراز هویت.
- حذف فیلد اجباری «کارت مورد بررسی» (dropdown). کارت اولین pending به
  صورت خودکار به عنوان hidden input ارسال می‌شود و ادمین نیازی به
  انتخاب ندارد.
- اصلاح خطای «The decision field is required.» — فیلد `decision` در
  کنترلر `nullable` شد. اگر فرم بدون دکمه submit شود، یک پیام واضح
  فارسی نمایش داده می‌شود.
- حذف `data-confirm` از دکمه‌ها تا کلیک مستقیم کار کند.
- تغییر فایل‌ها:
  - `app/Http/Controllers/Admin/KycController.php`
  - `resources/views/admin/kyc/show.blade.php`
  - `resources/views/admin/kyc/index.blade.php`

## 4) تاریخ شمسی و وقت تهران
- پنل ادمین همیشه فارسی و شمسی + Asia/Tehran است. این کار با
  `app()->setLocale('fa')` در `app/Http/Middleware/EnsureAdmin.php`
  انجام شد.
- در مینی‌اپ:
  - اگر زبان کاربر فارسی باشد: شمسی + Asia/Tehran.
  - اگر انگلیسی باشد: میلادی + UTC.
- همه‌ی viewها برای استفاده از `App\Support\PersianDate::format()`
  به‌روزرسانی شدند.
- تغییر فایل‌ها:
  - `app/Http/Middleware/EnsureAdmin.php`
  - تمام فایل‌های `resources/views/admin/*.blade.php` و
    `resources/views/app/*.blade.php` که تاریخ نمایش می‌دهند.

## نکات مهم
1. timezone در `config/app.php` روی `UTC` باقی مانده تا تاریخ‌های موجود
   در دیتابیس shift نشوند. تبدیل timezone در زمان نمایش انجام می‌شود.
2. ستون `users.photo_url` همچنان وجود دارد اما حالا به جای مسیر فایل
   محلی، لینک مستقیم Telegram CDN را نگه می‌دارد.
3. برای کاربرانی که قبلاً `photo_url = /avatars/{id}` داشتند، در اولین
   بازدید از مینی‌اپ یا پنل ادمین، `AvatarController` به صورت خودکار
   لینک تازه‌ای از Telegram می‌گیرد و redirect می‌کند.
4. اگر فایل `app/Jobs/RefreshUserProfilePhoto.php` در پروژه‌ی شما وجود
   دارد، آن را حذف کنید (در این ZIP وجود ندارد).

## نحوه‌ی استقرار
1. این ZIP را در مسیر ریشه‌ی پروژه‌ی Laravel خود باز کنید.
2. اگر از Git استفاده می‌کنید، می‌توانید تغییرات را commit کنید.
3. کش‌ها را پاک کنید:
   ```bash
   php artisan view:clear
   php artisan config:clear
   php artisan cache:clear
   ```
4. نیازی به migration نیست (هیچ ستون جدیدی اضافه نشده).
