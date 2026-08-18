# راهنمای اپراتور نسخه اول Telegram Ads

## اصل نسخه اول

ثبت، ویرایش عملیاتی، توقف و خواندن آمار Telegram Ads در V1 دستی است. در منابع رسمی عمومی بررسی‌شده API مستندی برای مدیریت کمپین تبلیغ‌دهنده وجود ندارد.

اپراتور فقط از رابط رسمی Telegram Ads استفاده می‌کند. موارد زیر ممنوع‌اند:

- ذخیره cookie یا session پنل در سامانه
- درخواست کد ورود از مشتری
- scraping یا browser automation
- استفاده از APIهای خصوصی یا reverse-engineered
- ذخیره seed phrase یا private key روی cPanel
- ثبت کمپین بدون audit و مدرک

متدهای Sponsored Messages در Telegram API برای نمایش تبلیغ به کاربران کلاینت هستند، نه ساخت کمپین.

منابع:

- [Telegram Ads: Getting Started](https://ads.telegram.org/getting-started)
- [Telegram Ads Terms](https://ads.telegram.org/tos)
- [Sponsored Messages API](https://core.telegram.org/api/sponsored-messages)

## نقش‌ها

- Support reviewer: محتوای سفارش و مقصد را بررسی می‌کند.
- KYC reviewer: احراز هویت و کارت ریالی را بررسی می‌کند.
- Ads operator: کمپین را در Telegram Ads ثبت می‌کند و وضعیت و snapshot آمار را به‌صورت دستی وارد سامانه می‌کند.
- Finance reviewer: مغایرت هزینه، اعتبار و refund را بررسی می‌کند.
- Super admin: تنظیمات حساس و دسترسی‌ها را مدیریت می‌کند.

تا حد امکان Ads operator نباید به فایل خام KYC دسترسی داشته باشد و KYC reviewer نباید به تنظیمات خزانه دسترسی داشته باشد.

## پیش‌شرط ثبت کمپین

قبل از برداشتن task:

- Order در queued_for_telegram است.
- payment_status برابر paid است.
- fund hold معتبر وجود دارد.
- آخرین CampaignRevision قفل شده است.
- متن، مقصد، زبان و targetها توسط پشتیبانی تأیید شده‌اند.
- پذیرش نسخه جاری Terms و Ads Policy ثبت شده است.
- planned_start_at و quote معتبرند یا مغایرت مالی تعیین تکلیف شده است.
- حساب عملیاتی Telegram Ads و بودجه کافی مشخص شده‌اند.

اگر هر مورد ناقص است، سفارش را به manual_attention ببرید؛ وضعیت موفق را حدس نزنید.

## ۱. گرفتن task

1. task از نوع submit_telegram_ad را باز کنید.
2. قابلیت claim/assign task در routeهای فعلی پنل پیاده نشده است؛ تا زمان افزودن آن، مسئولیت اپراتور باید با فرایند کنترل‌شده بیرونی ثبت شود و پنل نباید ادعای assignment خودکار داشته باشد.
3. public ID سفارش و revision number را تطبیق دهید.
4. از آخرین revision قفل‌شده استفاده کنید.
5. account label عملیاتی را قبل از ورود ثبت کنید.

## ۲. بررسی نهایی محتوا

- متن در قالب مربوط حداکثر ۱۶۰ نویسه با فاصله است.
- شکست خط، bullet، numbering و emoji افراطی ندارد.
- لینک متن و URL به یک مقصد می‌رسند.
- shortener یا IP address استفاده نشده است.
- مقصد فعال و مرتبط است.
- کانال یا ربات مقصد عکس و توضیح کامل دارد.
- زبان تبلیغ، مقصد و کانال هدف هماهنگ است.
- کانال هدف در کمپین کانال‌محور عمومی و دارای بیش از ۱۰۰۰ عضو است.
- محتوای ممنوع در Ads Policy وجود ندارد.

تأیید داخلی تضمین پذیرش Telegram نیست.

## ۳. ثبت در Telegram Ads

1. با حساب سازمانی مصوب و دارای 2FA وارد رابط رسمی شوید.
2. Create Ad را باز کنید.
3. internal title را با قالب قابل ردگیری وارد کنید:

       {ORDER_PUBLIC_ID}-{REVISION_NO}

4. ad text و destination را بدون بازنویسی سلیقه‌ای کپی کنید.
5. targetها، CPM و maximum budget را با سفارش تطبیق دهید.
6. Preview را با revision مقایسه کنید.
7. کمپین را ثبت کنید.
8. شناسه خارجی، account label، زمان و اپراتور را در TelegramSubmission ثبت کنید.
9. screenshot یا مدرک را در private storage بارگذاری کنید. ورودی proof برای TelegramSubmission در controller فعلی هنوز متصل نشده و پیش از الزام عملیاتی این مرحله باید تکمیل شود.
10. وضعیت سفارش را به telegram_review ببرید و رویداد audit ایجاد کنید.

اطلاعات حساب Telegram Ads یا موجودی کل حساب در screenshot کاربرپسند افشا نشود.

## نگاشت وضعیت

| وضعیت رسمی/عملیاتی | TelegramSubmission | Order |
|---|---|---|
| منتظر اپراتور | pending_operator | queued_for_telegram |
| ثبت شد | submitted | telegram_review |
| In Review | in_review | telegram_review |
| Approved ولی هنوز شروع نشده | approved | telegram_approved یا scheduled |
| Active | active | active |
| On Hold یا توقف تأییدشده | paused | paused |
| Declined | rejected | telegram_rejected |
| بودجه تمام یا پایان تأییدشده | completed | completed |

در UI مشتری:

- «ثبت در تلگرام»: آگهی در Telegram Ads ثبت شده و منتظر بررسی است.
- «تأیید تلگرام»: آگهی پذیرفته شده؛ به معنی شروع قطعی نمایش نیست.
- «در حال اجرا»: تنها وقتی کمپین واقعاً Active است.
- «متوقف‌شده»: هزینه نمایش‌های قبل از توقف نهایی است.
- «ردشده توسط تلگرام»: دلیل موجود نمایش داده می‌شود؛ سیاست مالی جداگانه اجرا می‌شود.

## رد توسط پشتیبانی

اگر محتوا قابل اصلاح است:

1. وضعیت را changes_requested قرار دهید، نه رد دائمی.
2. reason code و توضیح عملی بنویسید.
3. fund hold را طبق سیاست مصوب حفظ یا آزاد کنید.
4. کاربر revision جدید ایجاد می‌کند.
5. revision قبلی تغییر نمی‌کند.

لغو توسط پشتیبانی فقط برای مورد غیرقابل اصلاح، تخلف یا تصمیم مصوب استفاده شود.

## رد توسط Telegram

1. دلیل رسمی یا متن قابل مشاهده را عیناً و بدون افزودن حدس ثبت کنید.
2. proof و زمان resolved را ذخیره کنید.
3. سفارش را telegram_rejected کنید.
4. به کاربر امکان ساخت نسخه اصلاح‌شده یا تماس با پشتیبانی بدهید.
5. finance task برای reconciliation بسازید.

متن مالی پیشنهادی پس از تأیید حقوقی:

> این وضعیت بازپرداخت نقدی ندارد. پس از تطبیق مالی، هر مبلغی که برای نمایش مصرف نشده و از طرف Telegram نیز قطعی کسر نشده باشد، طبق شرایط سرویس به اعتبار تبلیغاتی داخلی تبدیل می‌شود.

این متن تا تعیین تکلیف دقیق کارمزد، budget قفل‌شده و cash balance نباید فعال شود.

## درخواست توقف و ادامه

درخواست کاربر ابتدا pause_requested یا resume_requested است؛ فوراً paused یا active نمایش داده نشود.

اپراتور:

1. task را دریافت می‌کند.
2. وضعیت فعلی Telegram Ads را ثبت می‌کند.
3. عملیات توقف یا ادامه را در رابط رسمی انجام می‌دهد.
4. پس از مشاهده نتیجه، وضعیت داخلی را نهایی می‌کند.
5. یک snapshot و audit event ایجاد می‌کند.

تغییرات Telegram ممکن است با تأخیر اعمال شوند. نمایش‌های انجام‌شده تا زمان توقف قطعی قابل برگشت نیستند.

## snapshot آمار

هر snapshot تصویری تجمعی از یک کمپین در زمان as_of_at است:

- impressions
- joins
- bot_starts
- spend_gram
- remaining_budget_gram
- source برابر manual
- proof_file_id
- recorded_by

قواعد ورود:

1. زمان منبع را به UTC تبدیل کنید.
2. همه اعداد را از یک صفحه و یک لحظه منطقی بردارید.
3. impressions، joins، starts و spend را تجمعی ثبت کنید.
4. واحد پول را با ستون و حساب عملیاتی تطبیق دهید.
5. screenshot را در storage خصوصی بگذارید. فرم فعلی snapshot هنوز proof_file_id دریافت نمی‌کند و این مورد شکاف V1 است.
6. اگر عدد از snapshot قبلی کمتر است، ذخیره عادی نکنید؛ manual_attention بسازید.
7. اصلاح باید با snapshot جدید و supersedes_id انجام شود و رکورد قبلی حذف نشود؛ فرم فعلی supersedes_id را دریافت نمی‌کند و تا تکمیل آن اصلاح فقط با ثبت توضیح عملیاتی خارج از این فرم قابل پیگیری است.

نمودارهای روزانه از تفاضل snapshotهای تجمعی ساخته می‌شوند. دو snapshot با as_of_at یکسان برای یک سفارش نباید بدون دلیل ثبت شوند.

## برنامه همگام‌سازی دستی

مقادیر زیر placeholder عملیاتی‌اند و مالک باید آن‌ها را تصویب کند:

- TODO-OWNER: هنگام هر تغییر وضعیت
- TODO-OWNER: کمپین فعال هر {ACTIVE_SYNC_HOURS} ساعت
- TODO-OWNER: کمپین متوقف یا پایان‌یافته یک snapshot نهایی
- TODO-OWNER: درخواست فوری کاربر با SLA برابر {SUPPORT_SLA}

تا وقتی ورود دستی است، عبارت «لحظه‌ای» یا «real-time» در UI استفاده نشود. متن مناسب:

> آخرین به‌روزرسانی: {AS_OF_AT}. آمار در نسخه فعلی به‌صورت دوره‌ای توسط اپراتور همگام می‌شود.

## reconciliation مالی

منابع:

- آمار Telegram Ads: مرجع نمایش و هزینه رسانه
- دفترکل داخلی: مرجع بدهی و اعتبار کاربر
- gateway verify/IPN: مرجع ورود وجه

در پایان یا رد کمپین:

1. snapshot نهایی ثبت شود.
2. spend نهایی با media hold مقایسه شود.
3. service fee طبق Terms محاسبه شود.
4. اختلاف غیرعادی به finance review برود.
5. بازگشت به cash balance و ad credit جدا ثبت شود.
6. هیچ موجودی با ویرایش مستقیم اصلاح نشود.

در پنل فعلی reconciliation سفارش‌های `telegram_rejected` و `completed` متصل است و هرکدام فقط پس از ثبت هزینه قطعی Telegram، fund hold را با سند متوازن می‌بندند. توقف/لغو، refund/chargeback و payout هنوز مسیر نهایی‌سازی مالی متناظر ندارند؛ این موارد باید به finance review بروند و نباید با تغییر دستی موجودی یا بستن fund hold دور زده شوند.

## مدیریت کانال‌های پیشنهادی

ادمین می‌تواند دسته جدید ایجاد کند و در هر دسته حداکثر ۳۰ جایگاه داشته باشد.

برای هر کانال:

- username و public URL
- عنوان و avatar
- زبان
- members count snapshot
- eligibility status
- last verified at
- دسته و position
- یادداشت داخلی

پیش از active شدن:

- عمومی‌بودن
- بیش از ۱۰۰۰ عضو برای کمپین کانال‌محور
- ارتباط موضوعی
- نبود محتوای آشکارا ممنوع
- زبان
- دسترس‌پذیری

بررسی شوند. فهرست پیشنهادی تضمین نمی‌کند Telegram آن کانال را برای نمایش بپذیرد یا موجودی تبلیغاتی داشته باشد.

## ارسال همگانی

1. audience filter و تعداد تقریبی را بررسی کنید.
2. پیام آزمایشی برای ادمین ارسال کنید.
3. زمان‌بندی و زبان را تأیید کنید.
4. Broadcast را به صف ببرید.
5. Jobها کاربران را دسته‌ای پردازش می‌کنند.
6. Job فعلی retry ثابت دارد و retry_after پاسخ 429 را parse نمی‌کند؛ این مورد پیش از ارسال انبوه واقعی باید تکمیل شود.
7. blocked/deactivated فعلاً به‌صورت خطای عمومی ذخیره می‌شود و classification مستقل هنوز پیاده نشده است.

ارسال همگانی داخل request پنل اجرا نمی‌شود و با refresh دوباره آغاز نمی‌شود.

## پایان شیفت

- task بدون owner باقی نمانده است.
- سفارش active بدون snapshot طبق SLA وجود ندارد.
- manual_attention بررسی شده است.
- failed payment callback و queue job بررسی شده‌اند.
- مغایرت دفترکل یا snapshot گزارش شده است.
- فایل KYC در دستگاه اپراتور دانلود و باقی نمانده است.
- از حساب Telegram Ads در دستگاه اشتراکی خارج شده‌اید.
- هیچ secret یا screenshot حساسی در chat شخصی ارسال نشده است.
