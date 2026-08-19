# TelegramAdsBot — تغییرات نسخه سوم (Patch)

این پوشه شامل تمام فایل‌های تغییر یافته است. ساختار فولدرها دقیقاً مطابق
با پروژه‌ی اصلی حفظ شده.

## 1) تبدیل همه اعداد فارسی به لاتین

**مشکل:** در نمایش تاریخ‌ها (از `PersianDate::format`) و در متن‌های ثابت
برخی از ویوها و فایل‌های زبان، اعداد فارسی (۰۱۲۳۴۵۶۷۸۹) نمایش داده
می‌شد.

**راه‌حل:**

### الف) `PersianDate::format()` اعداد رو به لاتین تبدیل می‌کنه

`IntlDateFormatter` با locale `fa_IR` به‌طور پیش‌فرض اعداد رو فارسی
برمی‌گردونه. حالا بعد از format، با متد جدید `toLatinDigits()` اعداد
فارسی (۰-۹) و عربی (٠-٩) به لاتین (0-9) تبدیل می‌شن.

این کار در یک جا (`PersianDate::format`) انجام می‌شه، پس تمام تاریخ‌های
شمسی در کل اپلیکیشن (پنل ادمین و مینی‌اپ) اعداد لاتین خواهند داشت.

### ب) متن‌های ثابت با اعداد فارسی اصلاح شدن

تعدادی از فایل‌های Blade و PHP و فایل‌های زبان متن‌هایی با اعداد فارسی
داشتن که مستقیم به کاربر نمایش داده می‌شد. اعداد در این متن‌ها به لاتین
تبدیل شدن:

- «۷ روز اخیر» → «7 روز اخیر»
- «۳۰ روز اخیر» → «30 روز اخیر»
- «۱۶۰ نویسه» → «160 نویسه»
- «۰٫۱ گرام» → «0.1 گرام»
- «۱ ساعت و ۲۴ ساعت» → «1 ساعت و 24 ساعت»
- «۳ حرف» → «3 حرف»
- «۳۰ کانال» → «30 کانال»
- «۵ دلار» → «5 دلار»

### فایل‌های تغییر یافته برای این مورد

- `app/Support/PersianDate.php` — اضافه شدن `toLatinDigits()` و فراخوانی
  آن در `format()`.
- `database/seeders/DatabaseSeeder.php` — متن سیاست تبلیغات.
- `resources/lang/fa/ui.php` — «7 روز اخیر» و «30 روز اخیر».
- `app/Http/Controllers/MiniApp/CampaignController.php` — پیام اعتبارسنجی.
- `app/Http/Controllers/MiniApp/KycController.php` — پیام اعتبارسنجی.
- `app/Http/Controllers/Admin/CatalogController.php` — پیام سقف کانال.
- `resources/views/admin/dashboard.blade.php` — لیبل‌های دوره.
- `resources/views/admin/transactions/index.blade.php` — «30 روز اخیر».
- `resources/views/admin/channels/index.blade.php` — «30 کانال».
- `resources/views/app/help.blade.php` — «160 نویسه».
- `resources/views/app/wallet/index.blade.php` — «5 دلار».
- `resources/views/app/campaigns/create.blade.php` — «0.1 گرام».
- `resources/views/app/identity/show.blade.php` — «1 ساعت و 24 ساعت».

> **نکته:** regex pattern هایی که اعداد فارسی رو اجازه می‌دن (مثل
> `pattern="[0-9۰-۹]{10}"` در فرم کد ملی) عمداً به‌جا موندن، چون کاربر
> ممکنه با کیبورد فارسی اعداد رو وارد کنه. این الگوها input رو می‌پذیرن،
> بعد `IranianIdentity::digits()` اون‌ها رو به لاتین تبدیل می‌کنه.

## 2) عکس پروفایل در پنل ادمین

**مشکل:** عکس پروفایل در مینی‌اپ درست نشون داده می‌شد ولی در پنل ادمین
خراب بود.

**علت:** در پنل ادمین، `<img src="{{ $user->photo_url }}">` مستقیم به
مقدار `photo_url` در دیتابیس لینک می‌کرد. این مقدار می‌تونست:

1. خالی باشه (در این صورت هیچ عکسی نشون داده نمی‌شد).
2. به مسیر قدیمی `/avatars/{id}` اشاره کنه (که درست کار می‌کرد).
3. به `https://api.telegram.org/file/bot...` اشاره کنه که **بعد از 1 ساعت
   منقضی می‌شد** — و این مشکل اصلی بود.

وقتی کاربر در مینی‌اپ لاگین می‌کرد، `SessionController` یک لینک Telegram
CDN تازه می‌گرفت و در `photo_url` ذخیره می‌کرد. اما این لینک فقط 1 ساعت
اعتبار داشت. بعد از اون، وقتی ادمین پرونده کاربر رو باز می‌کرد، عکس لود
نمی‌شد چون لینک منقضی شده بود.

**راه‌حل:**

### الف) Blade directive جدید `@avatarUrl($user)`

در `AppServiceProvider::boot()` یک directive اضافه شد که همیشه یک URL
**کارآمد** برای عکس کاربر برمی‌گردونه:

- اگه `photo_url` به Telegram CDN اشاره کنه و در 30 دقیقه اخیر
  به‌روزرسانی شده باشه → همون رو برمی‌گردونه (fast path).
- در غیر این صورت → route عمومی `avatar.show` رو برمی‌گردونه که
  `AvatarController::show()` رو صدا می‌زنه. این کنترلر:
  - اگه URL تازه باشه → مستقیم redirect می‌کنه.
  - اگه URL منقضی یا خالی باشه → خودکار از Telegram URL تازه می‌گیره،
    در دیتابیس ذخیره می‌کنه، بعد redirect می‌کنه.

اینطوری عکس همیشه در هر دو پنل ادمین و مینی‌اپ درست نشون داده می‌شه.

### ب) آپدیت منطق `User::refreshTelegramPhotoUrl`

- حالا `updated_at` رو هم بعد از ذخیره URL جدید به `now()` set می‌کنه،
  تا پنجره 30 دقیقه‌ای از زمان ذخیره URL جدید شروع بشه.
- اگه URL تکراری باشه (Telegram همون URL رو برگردونه)، `updated_at` رو
  touch می‌کنه تا پنجره تازگی restart بشه.

### ج) آپدیت `AvatarController::resolveUrl`

- حالا چک می‌کنه که آیا `photo_url` در 30 دقیقه اخیر به‌روزرسانی شده.
  اگه آره، مستقیم برش می‌گردونه (fast path، بدون فراخوانی Telegram API).
- اگه نه، `refreshTelegramPhotoUrl()` رو صدا می‌زنه که URL تازه می‌گیره.

### د) استفاده از `@avatarUrl` در همه ویوهای ادمین و مینی‌اپ

در 5 ویو ادمین و 2 ویو مینی‌اپ، `<img src="{{ data_get($user,'photo_url') }}">`
با `<img src="@avatarUrl($user)">` جایگزین شد.

### فایل‌های تغییر یافته برای این مورد

- `app/Providers/AppServiceProvider.php` — اضافه شدن directive `@avatarUrl`
  و متد `avatarUrl()`.
- `app/Models/User.php` — آپدیت `refreshTelegramPhotoUrl()` برای set
  کردن `updated_at`.
- `app/Http/Controllers/MiniApp/AvatarController.php` — آپدیت `resolveUrl()`
  با fast path 30 دقیقه‌ای.
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/users/show.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `resources/views/admin/kyc/index.blade.php`
- `resources/views/admin/kyc/show.blade.php`
- `resources/views/layouts/app.blade.php` (مینی‌اپ topbar)
- `resources/views/app/account.blade.php`

## نحوه‌ی استقرار

1. این ZIP را در مسیر ریشه‌ی پروژه‌ی Laravel خود باز کنید.
2. کش‌ها را پاک کنید:
   ```bash
   php artisan view:clear
   php artisan config:clear
   php artisan cache:clear
   ```
3. اگه قبلاً `db:seed` اجرا کرده‌اید و متن سیاست با اعداد فارسی در
   دیتابیس ذخیره شده، برای به‌روزرسانی متن:
   ```bash
   php artisan db:seed --force
   ```
   یا فقط جدول `policy_versions` رو trunc و دوباره seed کنید.

## نکات

- `@avatarUrl` هم با object کامل User کار می‌کنه و هم با array (مثل
  `data_get($user, '...')` در حلقه‌های foreach).
- اگه `APP_URL` درست تنظیم نشده باشه، route `avatar.show` به جای
  مطلق، نسبی برمی‌گردونه که در پنل ادمین ممکن است مشکل ایجاد کنه.
  مطمئن شو `APP_URL` در `.env` با دامنه‌ی کامل ست شده.
- برای کاربرانی که قبلاً در دیتابیس ثبت شدن و `photo_url` آن‌ها خالی یا
  منقضی هست، اولین بازدید از پنل ادمین یا مینی‌اپ، خودکار URL تازه از
  Telegram می‌گیره و در دیتابیس ذخیره می‌کنه.
