# TelegramAdsBot — patches overview (v3)

This package contains every file changed by the wizard-redesign patch
plus all follow-up fixes (v2: top loading bar + wizard button bug;
v3: mobile overflow + admin panel display + update.sh overhaul).

## Files included (paths are repo-relative — drop into your project root)

| Path | v1 | v2 | v3 | Notes |
| ---- | -- | -- | -- | ----- |
| `database/migrations/2026_08_19_000001_extend_campaign_revisions_for_ad_target_fields.php` | NEW | — | — | adds `daily_view_limit_per_user`, `ad_media_*`, `search_keywords` columns. |
| `app/Http/Controllers/MiniApp/CampaignController.php` | MODIFIED | — | — | new validation rules, derives `destination_type` from `placement_type`, suggested channels Persian-first. |
| `app/Models/CampaignRevision.php` | MODIFIED | — | — | new fillable + casts. |
| `resources/views/app/campaigns/create.blade.php` | MODIFIED | — | — | full wizard rewrite. |
| `resources/views/app/identity/show.blade.php` | MODIFIED | — | — | `data-disable-until-valid` on KYC form. |
| `resources/views/layouts/app.blade.php` | — | NEW | — | removes splash, adds top progress bar. |
| `resources/views/admin/orders/show.blade.php` | — | — | NEW | shows `placement_type`, `daily_view_limit_per_user`, `search_keywords`, `ad_media_*`, plus more financial/CPM/language fields. |
| `bin/update.sh` | — | — | NEW | removed SSL check, added explicit cache clear commands (view/config/route/cache), smarter pm2 restart (`startOrReload` → fallback to `restart`), `pm2 save`. |
| `resources/js/app.js` | MODIFIED | FIX | — | TDZ fix for wizard buttons, top progress bar logic, keyword chip input, branching wizard. |
| `resources/css/app.css` | MODIFIED | FIX | FIX | removed `.app-splash`, added `.nav-progress`, added overflow fixes for step 3 on mobile, ellipsis on channel-rows. |

## v3 changes (this update)

### Fix 1 — Horizontal overflow on step 3 (mobile)
The "کانال‌های هدف" section (step 3 of 6) was overflowing the viewport
from the left side on narrow mobile devices. Root causes:

1. `.channel-search-input` had `width: 100%` but its parent box
   `.channel-search` had `padding: 12px` — the input was wider than
   the box's content area, pushing it past the right edge.

2. `.channel-row` had flex children (`.channel-copy`) whose `small.ltr`
   line (e.g. `@long_username · 12,345 members`) was missing
   `white-space: nowrap` + `text-overflow: ellipsis` — long usernames
   pushed the row's right column off-screen.

3. The body/html/container had no `overflow-x: hidden` safety net, so
   any element that grew past the viewport showed up as a horizontal
   scrollbar.

**Fixes:**
- `.channel-search`: added `min-width: 0; max-width: 100%; overflow: hidden`
- `.channel-search-input`: added `min-width: 0; text-overflow: ellipsis`
- `.channel-search-results` + `.channel-chip`: added `min-width: 0`
- `.channel-list` + `.channel-row`: added `min-width: 0`
- `.channel-copy`: changed `flex: 1` → `flex: 1 1 auto`
- `.channel-copy small`: added `overflow: hidden; text-overflow: ellipsis; white-space: nowrap`
- `.channel-row .status-chip`: added `flex: 0 0 auto`
- `.category-tabs`: added `max-width: 100%`
- `.card`, `.card-head`, `.wizard-shell`, `.wizard-pane`, `.form-grid`,
  `.field`, `.field-row`: added `min-width: 0` so flex children can
  actually shrink
- `html`, `body`, `.mini-shell`, `.mini-content`: added `overflow-x: hidden`
  as a safety net

### Fix 2 — Admin order detail now shows all new fields
The admin panel's "Ad content" section previously showed:
- نوع مقصد (destination_type — no longer collected from the user)
- لینک مقصد (destination URL)
- پلن / فرکانس (plan + frequency_cap — frequency_cap is now obsolete)
- هدف نمایش (impression goal)

**Now shows:**
- محل نمایش تبلیغ (placement_type) — Channel/Bot/Search, human-readable
- لینک مقصد (destination URL) — with `overflow-wrap: anywhere` for long URLs
- پلن (plan)
- **محدودیت بازدید روزانه برای هر کاربر** (daily_view_limit_per_user)
- هدف نمایش (impression goal)
- **پیشنهاد CPM** (cpm_gram) — was missing
- **زبان تبلیغ** (language) — was missing
- **کلیدواژه‌های جستجو** (search_keywords) — only shown when present,
  rendered as blue chips
- **رسانه پیوست** (ad_media_path) — image/video badge + file path,
  only shown when present

Also added `overflow-wrap: anywhere` to the `ad_text` preview block so
long URLs / emoji runs don't break the layout.

### Fix 3 — `bin/update.sh` overhaul
Removed the SSL renewal check (step 6/6 → removed; certbot renewal
should be a cron job, not part of every deploy). Replaced the
"migrations + caches" step with explicit cache-clear commands that
run **before** the optimized cache is rebuilt:

```bash
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan event:clear   # best-effort, may not exist on older Laravel
# Then rebuild optimized caches:
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

PM2 restart is now smarter:
- Uses `pm2 startOrReload ecosystem.config.cjs --update` first (this is
  the right command when processes are already running — it picks up
  env var changes from `ecosystem.config.cjs` without dropping
  messages).
- Falls back to `pm2 restart ecosystem.config.cjs --update` if the
  reload fails (e.g. processes weren't started yet).
- Falls back to `pm2 restart all --update` if no ecosystem config
  exists in the project.
- Always calls `pm2 save` at the end so the process list survives a
  server reboot.

The script is now 5 steps (down from 6 — SSL step removed):

1. `git pull`
2. `composer install` + `npm ci` + `npm run build`
3. `migrate` + clear all caches (view/config/route/cache/event) +
   rebuild optimized caches
4. permissions (`storage/` + `bootstrap/cache/`)
5. reload services (php-fpm + nginx + pm2)

## How to deploy (v3)

1. Drop each file from this zip into the matching path in your project.
2. Run `npm run build` (or `npx vite build`) to re-bundle JS + CSS.
3. Run `php artisan migrate` to apply the migration (v1).
4. Run `sudo bash bin/update.sh` — it will now clear all caches, rebuild
   them, and restart PM2 properly.

## Verification checklist

After deploying v3:
- [ ] Open `/app/campaigns/create` on a narrow mobile viewport (≤375px)
      and navigate to step 3 — there should be NO horizontal scrollbar.
- [ ] Long channel usernames in step 3 should truncate with ellipsis
      (`@very_long_channel…`) instead of pushing off-screen.
- [ ] Submit a test order with `placement_type = search_results` and
      multiple keywords — then open the order in the admin panel.
      The "محتوای تبلیغ" card should show:
        - محل نمایش تبلیغ: جستجو
        - محدودیت بازدید روزانه برای هر کاربر: 3 بار در روز
        - کلیدواژه‌های جستجو: chip1, chip2, chip3
- [ ] Submit another order with `placement_type = channel_posts` and
      an attached image. The admin panel should show:
        - محل نمایش تبلیغ: کانال‌ها
        - رسانه پیوست: [تصویر badge] + file path
- [ ] Run `sudo bash bin/update.sh` — you should see output lines:
        - "clearing view / config / route / app caches"
        - "view:clear", "config:clear", "route:clear", "cache:clear"
        - "config:cache", "route:cache", "view:cache"
        - "pm2 processes reloaded (startOrReload)" or "pm2 processes restarted"
        - NO certbot / SSL step.
