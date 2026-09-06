# TelegramAdsBot — Patch v1 (Channel Avatar + Verified Badge + AJAX Pagination)

این پچ شامل تغییرات زیر است:

## خلاصه تغییرات

1. **رفع باگ نمایش کد `data_get($channel...` در لیست کانال‌های پیشنهادی**
   - در فایل `resources/views/app/campaigns/create.blade.php` خط ۴۲۹ (اصل) از
     `@{{ ltrim(...) }}` استفاده شده بود. علامت `@` قبل از `{{` در Blade به‌معنای
     «این عبارت را به‌صورت متن خام نمایش بده، پردازش نکن» است (برای فریم‌ورک‌های
     JS مثل Vue). به همین دلیل یوزرنیم کانال به‌جای نمایش، کد `{{ ltrim((string)
     data_get($channel, 'username', 'channel'), '@') }}` به‌صورت متن دیده می‌شد.
   - **رفع:** علامت `@` حذف شد و عبارت به `{{ '@' . ltrim((string) data_get($channel,
     'username', 'channel'), '@') }}` تغییر کرد تا Blade آن را پردازش کند.

2. **نمایش تیک تأیید (Verified Badge) روی عکس کانال‌های اضافه‌شده**
   - یک badge آبی‌رنگ با چک‌مارک سفید (مشابه تلگرام) به گوشه پایین-راست عکس
     پروفایل کانال اضافه شد.
   - این badge در دو جا نمایش داده می‌شود:
     - پنل ادمین → فهرست کانال‌ها (`resources/views/admin/channels/index.blade.php`)
     - صفحه ثبت سفارش → انتخاب کانال هدف (`resources/views/app/campaigns/create.blade.php`)
   - هر کانالی که در `suggested_channels` وجود دارد (یعنی ادمین اضافه کرده) badge
     می‌گیرد تا از کانال‌های دستی (که کاربر تایپ می‌کند) متمایز شود.

3. **صفحه‌بندی بدون رفرش (AJAX) برای حالت «همه» کانال‌ها**
   - وقتی کاربر در ثبت سفارش روی تب «همه» کلیک می‌کند و تعداد کانال‌ها بیشتر از
     ۱۲ باشد، نوار صفحه‌بندی ظاهر می‌شود (۱ ۲ ۳ … ›).
   - کلیک روی هر صفحه، کانال‌های آن صفحه را از طریق AJAX لود می‌کند بدون اینکه
     صفحه رفرش شود یا state ویزارد (متن تبلیغ، کانال‌های انتخابی و …) از دست برود.
   - کانال‌های انتخاب‌شده در صفحات دیگر به‌صورت hidden input حفظ می‌شوند تا در
     submit فرم، انتخاب‌ها از دست نرود.
   - endpoint جدید: `GET /app/channels/page` (با middleware `throttle:miniapp-channel-search`).

## فایل‌های تغییر یافته

| مسیر | نوع تغییر |
|------|-----------|
| `resources/views/app/campaigns/create.blade.php` | رفع باگ `@{{`، اضافه‌شدن badge، اضافه‌شدن pagination container |
| `resources/views/admin/channels/index.blade.php` | اضافه‌شدن badge روی avatar جد کانال‌ها |
| `resources/css/app.css` | استایل‌های `.channel-verified-badge` و `.channel-pagination*` |
| `resources/js/app.js` | لاجیک AJAX pagination + init when «همه» tab فعال شود |
| `app/Http/Controllers/MiniApp/CampaignController.php` | متد جدید `paginateChannels()` + helper های `escapeHtml` و `renderPagination` |
| `routes/web.php` | route جدید `GET /app/channels/page` |

## نحوه نصب

1. این ZIP را extract کنید.
2. هر فایل را با مسیر نسبی خود در پروژه Laravel جایگزین کنید. مثلاً فایل
   `resources/views/app/campaigns/create.blade.php` در این ZIP باید جایگزین
   `resources/views/app/campaigns/create.blade.php` در پروژه شما شود.
3. اگر قبلاً `php artisan view:clear` اجرا نمی‌کردید، حتماً اجرا کنید تا
   cache قالب‌های Blade پاک شود:
   ```bash
   php artisan view:clear
   php artisan route:clear
   php artisan config:clear
   ```
4. اگر از Vite برای کامپایل CSS/JS استفاده می‌کنید، باید asset ها را rebuild کنید:
   ```bash
   npm run build
   # یا در محیط dev:
   npm run dev
   ```

## وابستگی‌ها

- هیچ migration دیتابیسی لازم نیست (ستون `avatar_url` از قبل در جدول
  `suggested_channels` وجود دارد).
- هیچ پکیج Composer جدیدی لازم نیست.
- نیازی به تغییر در `.env` نیست.

## نکات

- اگر تعداد کانال‌های پیشنهادی فعال کمتر از ۱۲ باشد، نوار صفحه‌بندی به‌طور خودکار
  مخفی می‌شود.
- اگر کاربر روی یک دسته خاص کلیک کند (مثلاً «سرگرمی»)، نوار صفحه‌بندی مخفی
  می‌شود چون هر دسته نهایتاً ۳۰ کانال دارد که در یک صفحه جا می‌شود.
- انتخاب‌های کاربر در صفحات مختلف حفظ می‌شوند (به‌صورت hidden input در فرم).
- در صفحه ادمین، badge تأیید روی تمام کانال‌های جد نمایش داده می‌شود چون همه‌شان
  توسط ادمین اضافه شده‌اند.
