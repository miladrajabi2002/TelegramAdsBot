# TelegramAdsBot — patches overview

This package contains every file changed by the wizard-redesign patch.

## Files included (paths are repo-relative — drop into your project root)

| Path | Status |
| ---- | ------ |
| `database/migrations/2026_08_19_000001_extend_campaign_revisions_for_ad_target_fields.php` | NEW — adds the new `daily_view_limit_per_user`, `ad_media_*`, `search_keywords` columns to `campaign_revisions`. |
| `app/Http/Controllers/MiniApp/CampaignController.php` | MODIFIED — new validation rules for the redesigned form, derives `destination_type` from `placement_type`, stores new fields, suggested-channel list now ordered Persian-first. |
| `app/Models/CampaignRevision.php` | MODIFIED — new fillable fields + casts for `search_keywords` and `daily_view_limit_per_user`. |
| `resources/views/app/campaigns/create.blade.php` | MODIFIED — full wizard rewrite. See breakdown below. |
| `resources/views/app/identity/show.blade.php` | MODIFIED — adds `data-disable-until-valid` to the KYC form so the submit button stays disabled until the form is valid. |
| `resources/js/app.js` | MODIFIED — branching wizard, keyword-chip input, iOS-style live preview, generic disable-until-valid helper, image+video preview support. |
| `resources/css/app.css` | MODIFIED — iOS Telegram preview component, 3-button placement selector, 4-button frequency selector, keyword chips, step-2 overflow fix. |

## Wizard structure (after the patch)

| Step | Title | What changed |
| ---- | ----- | ------------ |
| 1 | عنوان و هدف | Removed "نوع مقصد" (destination_type) select. Renamed "لینک مقصد" → "لینکی که می‌خواهید تبلیغ کنید". Replaced "محل نمایش تبلیغ" dropdown with a 3-button selector: جستجو / ربات‌ها / کانال‌ها. |
| 2 | محتوای تبلیغ | Dynamic fields based on placement_type. For کانال‌ها: ad_text + "افزودن تصویر یا ویدیو" upload. For جستجو: ad_text + "جستجوی هدف" multi-keyword chip input (min 4 chars). For ربات‌ها: ad_text only. ALL three show "پارامترهای هدف بعد از ایجاد شدن نمی‌تواند تغییر کند" notice + "محدودیت بازدید روزانه برای هر کاربر" 4-button selector. Live iOS Telegram preview switches per placement. Emoji is allowed in `ad_text`. |
| 3 | کانال‌های هدف / ربات‌های هدف / جستجوی هدف | Same channel/bot picker; the title + label change based on placement_type. Suggested-channel list ordered with Persian-language (ایران) channels first. |
| 4 | نحوه اجرا | Same as before, but `frequency_cap` is no longer collected here (replaced by `daily_view_limit_per_user` on step 2). All numeric inputs have `inputmode="numeric"` so the numeric keyboard opens. |
| 5 | قیمت و قوانین | Unchanged. |
| 6 | روش پرداخت | Unchanged. |

## How to deploy

1. Backup your current project (or commit current state to git).
2. Run `php artisan migrate` to apply the new migration. The migration
   is non-destructive (adds nullable columns only) and is safe to roll
   back via `php artisan migrate:rollback`.
3. Drop each file from this zip into the matching path in your project.
4. Run `npm run build` (or `npx vite build`) to re-bundle the JS + CSS
   from `resources/js/app.js` and `resources/css/app.css`.
5. Clear Laravel view cache: `php artisan view:clear`.

## Notes

- The old `frequency_cap` column is kept on `campaign_revisions` for
  backward compatibility with existing rows; only the new
  `daily_view_limit_per_user` column is populated going forward.
- The `destination_type` column is kept on the schema but the form no
  longer collects it. The controller derives it from `placement_type`
  on submit (channel_posts→channel, bot_messages→bot,
  search_results→channel).
- Persian-digit → English-digit conversion was already implemented in
  `resources/js/app.js` (lines ~800-880) and continues to work on every
  numeric input. The new `daily_view_limit_per_user` field is a radio
  button so no conversion is needed there.
- The `inputmode="numeric"` attribute has been added to all numeric
  inputs (`impression_goal`, `media_budget_toman`, `cpm_gram`) so iOS
  and Android show the numeric keyboard automatically.
