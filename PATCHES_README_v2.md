# TelegramAdsBot — patches overview (v2)

This package contains every file changed by the wizard-redesign patch
plus the v2 follow-up fixes (top loading bar + wizard button bug).

## Files included (paths are repo-relative — drop into your project root)

| Path | v1 | v2 | Notes |
| ---- | -- | -- | ----- |
| `database/migrations/2026_08_19_000001_extend_campaign_revisions_for_ad_target_fields.php` | NEW | — | adds `daily_view_limit_per_user`, `ad_media_*`, `search_keywords` columns to `campaign_revisions`. |
| `app/Http/Controllers/MiniApp/CampaignController.php` | MODIFIED | — | new validation rules, derives `destination_type` from `placement_type`, suggested channels ordered Persian-first. |
| `app/Models/CampaignRevision.php` | MODIFIED | — | new fillable + casts for the new fields. |
| `resources/views/app/campaigns/create.blade.php` | MODIFIED | — | full wizard rewrite (3-button placement selector, dynamic step-2 fields, iOS preview, etc.). |
| `resources/views/app/identity/show.blade.php` | MODIFIED | — | adds `data-disable-until-valid` to KYC form. |
| `resources/views/layouts/app.blade.php` | — | NEW | removes the full-screen splash, adds a top progress bar element below the header. |
| `resources/js/app.js` | MODIFIED | FIX | (1) fixes the disabled-button bug — `isStepValid` + `updateNextButtonState` are now declared BEFORE `applyPlacement` (TDZ fix). (2) removes splash loader code. (3) adds top progress-bar lifecycle handlers. |
| `resources/css/app.css` | MODIFIED | FIX | removes `.app-splash` styles, adds `.nav-progress` thin blue progress bar styles. |

## v2 changes (this update)

### Fix 1 — Disabled Continue button on wizard step 1
**Root cause:** `isStepValid` and `updateNextButtonState` were declared as
`const` AFTER the line `if (selectedPlacementInput) applyPlacement(...)`,
which calls them on init. JavaScript `const` declarations are subject to
the Temporal Dead Zone — referencing them before their declaration line
throws a `ReferenceError`, which silently broke the entire wizard.

**Fix:** moved `isStepValid` and `updateNextButtonState` declarations
above `applyPlacement`. The init call now succeeds, listeners are bound
correctly, and the Next/Submit buttons enable/disable properly based on
step validity.

This fix applies to all wizard steps, not just step 1. The same TDZ bug
was blocking step 2 (ad content + media + keywords + daily-view-limit)
and the final submit button as well.

### Fix 2 — Top loading progress bar (replaces full-screen splash)
**Before:** every navigation triggered a full-screen branded splash that
covered the whole mini-app for ~1.6s. Annoying when navigating between
pages.

**After:** the splash is completely gone. A thin (3px) blue progress bar
sits under the `mini-topbar` header and:
- Starts at 0% and grows to 80% (with a slow easing) when the user
  clicks a same-origin link or submits a form.
- Jumps to 100% (200ms fast transition) when the next page's `load`
  event fires (or `pagehide` / `beforeunload` fires).
- Fades out and resets to 0% so it's ready for the next navigation.

The bar uses CSS transitions only — no JS-driven requestAnimationFrame
loops. Visibility is driven by toggling `.is-loading` and `.is-complete`
classes on the `[data-nav-progress]` element.

### Files added in v2

- `resources/views/layouts/app.blade.php` — needed because the splash
  HTML was hard-coded in the layout. We replaced the splash block with
  a `<div class="nav-progress" data-nav-progress>` element inside the
  header.

## How to deploy (v2)

1. Drop each file from this zip into the matching path in your project,
   replacing the existing file.
2. Run `npm run build` (or `npx vite build`) to re-bundle JS + CSS.
3. Run `php artisan view:clear` to drop the cached Blade templates
   (important — without this the layout change won't be picked up).
4. No new migration in v2 — the v1 migration is still required.

## Verification checklist

After deploying v2:
- [ ] Open `/app/campaigns/create` — you should see the wizard with
      the Continue button DISABLED on step 1 (because fields are empty).
- [ ] Type a title, type a URL, pick a placement — the Continue button
      should become ENABLED immediately when all three are filled.
- [ ] Click Continue — you should see the thin blue bar grow under the
      header, then complete and fade out as step 2 loads.
- [ ] Navigate between any pages (home → campaigns → wallet) — the bar
      should appear briefly under the header each time.
