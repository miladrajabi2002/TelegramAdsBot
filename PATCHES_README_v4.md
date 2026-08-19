# TelegramAdsBot — patches v4

This package contains every file changed by the v4 patch, which fixes three
bugs reported on 2026-08-19:

1. **ZarinPal create-payment rejected with `"ZarinPay rejected payment creation."`**
   at `app/Services/PaymentService.php:132`.
2. **Admin panel → Channels → "Fetch info" button does nothing** when typing
   `@username` and pressing the button.
3. **Admin panel → Transactions page crashes with
   `"Object of class App\Enums\PaymentPurpose could not be converted to string"`
   at `admin/transactions/index.blade.php:78` (compiled view path).

## Files included (paths are repo-relative — drop into your project root)

| Path | Status | Bug | Notes |
| ---- | ------ | --- | ----- |
| `app/Services/Payments/LiveZarinPayGateway.php` | MODIFIED | #1 | Adds support for multiple ZarinPal response shapes (flat, nested `data.*`, Zarinpal-style `data.authority + data.code`, modern `ok + data.link/token`). Adds explicit logging of the full response when the request fails. Catches `ConnectionException` so the operator sees a clear "gateway is unreachable" message instead of a generic "rejected payment creation" error when the network is down. Auto-builds a payment link from the configured base host when the gateway returned only an authority. |
| `app/Services/PaymentService.php` | MODIFIED | #1 | When the create-payment validation block rejects the response, the new code logs the EXACT reason (`gateway_returned_success_false`, `missing_authority`, `missing_payment_link`, `untrusted_payment_host`) plus the full raw response and the configured `payment_hosts`. The thrown exception now carries a specific message that tells the operator whether to fix credentials, restart the gateway, or update `ZARINPAY_PAYMENT_HOSTS` in `.env`. |
| `app/Http/Controllers/Admin/CatalogController.php` | MODIFIED | #2 | `lookupChannel()` now: (1) wraps `validate()` in a try/catch so a 422 always returns JSON (the previous version returned HTML which broke the JS `fetch().json()` parser); (2) catches all exceptions from `TelegramBotClient` so a missing bot token or network error returns a JSON `{error: "telegram_unreachable"}` instead of an HTML 500 page; (3) tolerates `getChatMemberCount()` failing — the lookup still succeeds with `members: null`. |
| `resources/views/admin/channels/index.blade.php` | MODIFIED | #2 | The "Fetch info" button JS now: (1) wraps the entire IIFE in a `DOMContentLoaded` listener + `try/catch` so any init error is logged to the browser console instead of silently killing the button; (2) rebuilds `lookupUrl` from `window.location.pathname` when the route cache is stale (the previous version silently returned when the URL was empty); (3) detects 403 (missing `catalog.manage` permission) and 422 (validation) and shows a specific message; (4) detects non-JSON responses (session expired → admin login page returned as HTML) and prompts the operator to re-login; (5) syncs the cleaned username back into the input field so the form submits a normalized value. |
| `resources/views/admin/transactions/index.blade.php` | MODIFIED | #3 | Introduces two safe closures (`$enumValue` and `$humanize`) in the `@php` block at the top of the view. These accept any of: a string, a PHP `UnitEnum` instance (returns `->value`), a Model object with a `->value` attribute, or `null`. The three places that previously did `str((string) data_get($x, 'purpose'/'type', ...))` now call `$humanize(...)` instead. Also tightens the `provider`/`currency` casts in the same table so a null column no longer crashes PHP 8.1+ deprecation warnings in `strtoupper()`. |
| `resources/views/admin/transactions/show.blade.php` | MODIFIED | #3 (defensive) | `strtoupper($intent->provider)` → `strtoupper((string) ($intent->provider ?? 'ledger'))` so a null provider column doesn't trigger a PHP 8.1 deprecation warning on the transaction detail page. |

## How to deploy (v4)

1. Drop each file from this zip into the matching path in your project,
   replacing the existing file.
2. Run `php artisan view:clear` to drop the cached Blade templates
   (important — without this none of the Blade changes will be picked up).
3. Run `php artisan config:clear` if you have a cached config and want
   the new logging to take effect immediately.
4. Run `npm run build` (or `npx vite build`) — NOT required for this patch
   because no JS lives in `resources/js/app.js`; all changes are inside
   Blade templates. But running it is safe.
5. No new migration in v4.

## Bug #3 — root cause analysis

The `PaymentIntent` model casts the `purpose` column to the
`App\Enums\PaymentPurpose` enum:

```php
// app/Models/PaymentIntent.php
protected function casts(): array
{
    return [
        'purpose' => PaymentPurpose::class,
        'status'  => PaymentStatus::class,
        // ...
    ];
}
```

So when the admin opens `/admin/transactions`, the controller runs
`PaymentIntent::query()->paginate(25)` and every row's `purpose` attribute
is hydrated as a `PaymentPurpose` enum instance (e.g.
`PaymentPurpose::WalletTopUp`), NOT as the string `'wallet_top_up'`.

The blade template then did:

```php
str((string) data_get($transaction, 'purpose', data_get($transaction, 'type', 'payment')))
       ->replace('_', ' ')
       ->title()
```

The `(string)` cast on a PHP enum throws
`Object of class App\Enums\PaymentPurpose could not be converted to string`
unless the enum implements `__toString()` — which backed enums do NOT do
automatically in PHP 8.1+. The correct way is `$enum->value`.

The fix introduces `$enumValue($value, $fallback)` which knows how to extract
the scalar value from any of the inputs we're likely to see:

```php
$enumValue = static function (mixed $value, string $fallback = ''): string {
    if ($value === null || $value === '') return $fallback;
    if (is_string($value)) return $value;
    if (is_int($value)) return (string) $value;
    if ($value instanceof \UnitEnum) {
        $case = $value->value ?? $value->name ?? null;
        return is_scalar($case) ? (string) $case : ($value->name ?? $fallback);
    }
    if (is_object($value)) {
        if (isset($value->value) && is_scalar($value->value)) return (string) $value->value;
        if (method_exists($value, '__toString')) return (string) $value;
    }
    return $fallback;
};
```

And `$humanize($value, $fallback)` which calls `$enumValue` then applies the
`replace('_', ' ')->title()` transformation, matching the previous display
behavior exactly.

The three call-sites that were changed:

| Where | Before | After |
| ----- | ------ | ----- |
| Transactions table → "Transaction" column | `str((string) data_get($transaction, 'purpose', ...))->replace('_', ' ')->title()` | `$humanize(data_get($transaction, 'purpose', ...), 'Payment')` |
| Ledger journals table → "Journal" column | `str((string) data_get($journal, 'type', 'journal'))->replace('_', ' ')->headline()` | `$humanize(data_get($journal, 'type', 'journal'), 'Journal')` |
| Payouts table → "Type" column | `str((string) data_get($payout, 'type', 'refund'))->replace('_', ' ')->headline()` | `$humanize(data_get($payout, 'type', 'refund'), 'Refund')` |

Note: `head()` vs `title()` — the previous code used `headline()` for journals
and payouts, but `title()` for transactions. `headline()` capitalizes every
word AND `ucwords()` on the snake_case boundaries, while `title()` is just
`ucwords()`. In practice both produce the same output for simple values like
`'wallet_top_up'` → `'Wallet Top Up'`. I kept `title()` for consistency with
the original transactions code, but if you want strict visual parity with
the old behavior, change `->title()` to `->headline()` in the helper.

## How to verify the ZarinPal fix (bug #1)

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

## How to verify the channel-lookup fix (bug #2)

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

## How to verify the transactions page fix (bug #3)

1. Log in to the admin panel, go to `/admin/transactions`.
2. The page should now load without the
   `"Object of class App\Enums\PaymentPurpose could not be converted to string"`
   error.
3. The "Transaction" column in the payments table should display either:
   - The `description` field of the `PaymentIntent` (when set), OR
   - The humanized `purpose` enum value, e.g. `Wallet Top Up` or
     `Order Payment`.
4. The "Journal" column in the ledger section should show the humanized
   `type` field, e.g. `Seed Wallet` → `Seed Wallet`.
5. The "Type" column in the payouts section should show the humanized
   `type` field, e.g. `Refund` or `Withdrawal`.
6. Click any transaction → the detail page should also load without error
   and show the provider name in uppercase (e.g. `ZARINPAY` or `LEDGER`).

## Why this fixes "it worked yesterday"

### Bug #1 — ZarinPal

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

### Bug #3 — Enum to string

The blade template was written before `PaymentIntent` started casting
`purpose` to the `PaymentPurpose` enum. When that cast was added (commit
`264730c`), the page silently broke for any row that had a non-null
`purpose` value — but it kept working for empty/null `purpose` rows because
the `data_get(..., 'payment')` fallback kicked in. The fix normalizes all
three call-sites to use a single helper that knows how to extract the
scalar value from an enum OR a string, so future columns added to the
`casts()` array won't trigger the same crash.

## Verification checklist

After deploying v4:

- [ ] Open `/admin/transactions`. The page should load without the
      `PaymentPurpose could not be converted to string` error.
- [ ] The "Transaction" column should show human-readable labels like
      `Wallet Top Up` or `Order Payment` (or the saved `description`
      when present).
- [ ] Click any transaction → the detail page should load and show
      `ZARINPAY` (or `NOWPAYMENTS` / `LEDGER`) in the page header.
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
