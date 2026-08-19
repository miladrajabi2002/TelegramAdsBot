# TelegramAdsBot — patches v4

This package contains every file changed by the v4 patch, which fixes two
bugs reported on 2026-08-19:

1. **ZarinPal create-payment rejected with `"ZarinPay rejected payment creation."`**
   at `app/Services/PaymentService.php:132`.
2. **Admin panel → Channels → "Fetch info" button does nothing** when typing
   `@username` and pressing the button.

## Files included (paths are repo-relative — drop into your project root)

| Path | Status | Notes |
| ---- | ------ | ----- |
| `app/Services/Payments/LiveZarinPayGateway.php` | MODIFIED | Adds support for multiple ZarinPal response shapes (flat, nested `data.*`, Zarinpal-style `data.authority + data.code`, modern `ok + data.link/token`). Adds explicit logging of the full response when the request fails. Catches `ConnectionException` so the operator sees a clear "gateway is unreachable" message instead of a generic "rejected payment creation" error when the network is down. Auto-builds a payment link from the configured base host when the gateway returned only an authority. |
| `app/Services/PaymentService.php` | MODIFIED | When the create-payment validation block rejects the response, the new code logs the EXACT reason (`gateway_returned_success_false`, `missing_authority`, `missing_payment_link`, `untrusted_payment_host`) plus the full raw response and the configured `payment_hosts`. The thrown exception now carries a specific message that tells the operator whether to fix credentials, restart the gateway, or update `ZARINPAY_PAYMENT_HOSTS` in `.env`. |
| `app/Http/Controllers/Admin/CatalogController.php` | MODIFIED | `lookupChannel()` now: (1) wraps `validate()` in a try/catch so a 422 always returns JSON (the previous version returned HTML which broke the JS `fetch().json()` parser); (2) catches all exceptions from `TelegramBotClient` so a missing bot token or network error returns a JSON `{error: "telegram_unreachable"}` instead of an HTML 500 page; (3) tolerates `getChatMemberCount()` failing — the lookup still succeeds with `members: null`. |
| `resources/views/admin/channels/index.blade.php` | MODIFIED | The "Fetch info" button JS now: (1) wraps the entire IIFE in a `DOMContentLoaded` listener + `try/catch` so any init error is logged to the browser console instead of silently killing the button; (2) rebuilds `lookupUrl` from `window.location.pathname` when the route cache is stale (the previous version silently returned when the URL was empty); (3) detects 403 (missing `catalog.manage` permission) and 422 (validation) and shows a specific message; (4) detects non-JSON responses (session expired → admin login page returned as HTML) and prompts the operator to re-login; (5) syncs the cleaned username back into the input field so the form submits a normalized value. |

## How to deploy (v4)

1. Drop each file from this zip into the matching path in your project,
   replacing the existing file.
2. Run `php artisan view:clear` to drop the cached Blade templates
   (important — without this the JS change won't be picked up).
3. Run `php artisan config:clear` if you have a cached config and want
   the new logging to take effect immediately.
4. Run `npm run build` (or `npx vite build`) — NOT required for this patch
   because no JS lives in `resources/js/app.js`; all changes are inside
   Blade templates. But running it is safe.
5. No new migration in v4.

## How to verify the ZarinPal fix

After deploying, try a small test payment (e.g. 10,000 Toman = 100,000 IRR).
Open `storage/logs/laravel.log` while you do this — you should now see
one of these structured log lines:

- `ZarinPay create-payment response` (debug) — gateway returned success,
  includes whether `payment_link` and `authority` were present.
- `ZarinPay create-payment was not successful` (warning) — gateway returned
  an HTTP error or `success:false`, includes the full redacted response.
- `ZarinPay create-payment network failure` (error) — the request never
  reached the gateway (DNS, TLS, connect timeout, etc.).
- `ZarinPay create-payment rejected by PaymentService` (warning) — the
  gateway responded but PaymentService rejected the result. Includes the
  `rejection_reason` (one of `gateway_returned_success_false`,
  `missing_authority`, `missing_payment_link`, `untrusted_payment_host`).

The most common cause of "it worked yesterday" breakage is
`untrusted_payment_host` — ZarinPal started returning the payment link on a
slightly different host (e.g. `payment.zarinmee.ir` instead of `zarinmee.ir`).
Fix it by adding the new host to `ZARINPAY_PAYMENT_HOSTS` in `.env`:

```
ZARINPAY_PAYMENT_HOSTS=zarinmee.ir,mock.zarinpay.test,payment.zarinmee.ir
```

Then `php artisan config:clear` and try again.

## How to verify the channel-lookup fix

1. Log in to the admin panel, go to `/admin/channels`.
2. Open the browser dev tools → Console.
3. Type `@somechannel` into the username field and click "دریافت اطلاعات".
4. If the lookup works, the title/members/avatar fields are auto-filled and
   a preview block appears below the input.
5. If it fails, the console now shows a specific error like:
   - `[admin.channels] lookup failed { status: 403 }` → the admin role
     doesn't have `catalog.manage`. Update the admin's role or permissions
     in the database.
   - `[admin.channels] lookup URL rebuilt from path: /admin/channels/lookup`
     → the route cache was stale and we rebuilt the URL. Run
     `php artisan route:clear` to fix the cache.
   - `[admin.channels] lookup returned non-JSON content-type: text/html`
     → the admin session expired mid-request. Re-login and try again.
   - `[admin.channels] lookup network error: ...` → JS-level error
     (CORS, DNS, etc.). Check the browser's Network tab for details.

## Why this fixes "it worked yesterday"

The previous `LiveZarinPayGateway::createPayment` was strict about the
response shape — it only accepted top-level `payment_link` and `authority`
fields. When ZarinPal (or the zarinmee.ir proxy) tweaks their API to nest
these under `data.*` or to use `link`/`token` instead of
`payment_link`/`authority`, the create-payment result had `paymentLink: null`
and PaymentService rejected it with the generic "ZarinPay rejected payment
creation." error.

The new code accepts all of these shapes:

| Shape | Example |
| ----- | ------- |
| Flat | `{success, payment_link, authority, message}` |
| Nested | `{success, data: {payment_link, authority}, message}` |
| Zarinpal-style | `{data: {authority, code: 100}, errors: []}` |
| Modern | `{ok: true, data: {link, token}}` |

And as a last resort, if the gateway returns only an `authority`, the code
builds the payment link itself: `{base_url_without_/api}/payment/{authority}`.

The same permissiveness is applied to `verifyPayment()` — both create and
verify now handle the same response-shape drift.

## Verification checklist

After deploying v4:

- [ ] Try a small ZarinPal payment. Open `storage/logs/laravel.log` and
      verify you see a `ZarinPay create-payment response` debug line.
- [ ] If the payment still fails, the log now shows the EXACT reason —
      follow the message to fix the config (usually `ZARINPAY_PAYMENT_HOSTS`).
- [ ] Open `/admin/channels`, type a channel username, click "دریافت اطلاعات".
      The title/members/avatar should auto-fill and a preview block should
      appear.
- [ ] Open browser dev tools → Console. If anything goes wrong you should
      now see a clear error message starting with `[admin.channels]`.
- [ ] If the lookup still doesn't work, check the admin's role has
      `catalog.manage` permission (or is `super_admin`).
