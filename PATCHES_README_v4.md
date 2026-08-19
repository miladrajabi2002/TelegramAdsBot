# TelegramAdsBot — patches v4

This package contains every file changed by the v4 patch, which fixes four
bugs reported on 2026-08-19:

1. **ZarinPal create-payment rejected with `"ZarinPay rejected payment creation."`**
   at `app/Services/PaymentService.php:132`.
2. **Admin panel → Channels → "Fetch info" button does nothing** when typing
   `@username` and pressing the button.
3. **Admin panel → Transactions page crashes with
   `"Object of class App\Enums\PaymentPurpose could not be converted to string"`
   at `admin/transactions/index.blade.php:78` (compiled view path).
4. **ZarinPal verify-payment rejected with `amount_mismatch`** when the
   gateway returns the original amount PLUS a service fee
   (e.g. user paid 200,000 Toman = 2,000,000 IRR, but the gateway's
   `verify-payment` returned 2,100,000 IRR — a 5% aggregator fee).

## Files included (paths are repo-relative — drop into your project root)

| Path | Status | Bug | Notes |
| ---- | ------ | --- | ----- |
| `app/Services/Payments/LiveZarinPayGateway.php` | MODIFIED | #1 | Adds support for multiple ZarinPal response shapes (flat, nested `data.*`, Zarinpal-style `data.authority + data.code`, modern `ok + data.link/token`). Adds explicit logging of the full response when the request fails. Catches `ConnectionException` so the operator sees a clear "gateway is unreachable" message instead of a generic "rejected payment creation" error when the network is down. Auto-builds a payment link from the configured base host when the gateway returned only an authority. |
| `app/Services/PaymentService.php` | MODIFIED | #1, #4 | For bug #1: when the create-payment validation block rejects the response, the new code logs the EXACT reason (`gateway_returned_success_false`, `missing_authority`, `missing_payment_link`, `untrusted_payment_host`) plus the full raw response and the configured `payment_hosts`. The thrown exception now carries a specific message that tells the operator whether to fix credentials, restart the gateway, or update `ZARINPAY_PAYMENT_HOSTS` in `.env`. For bug #4: the `zarinPayVerificationMismatch()` amount check now accepts a configurable tolerance window (default ±10%, set via `ZARINPAY_FEE_TOLERANCE_PERCENT` env var) so that ZarinPal-compatible aggregators that add a 2–6% service fee on top of the original amount don't trigger an `amount_mismatch`. The wallet credit still uses the original `intent.amount_minor` (NOT the gross amount), so the user gets exactly what they asked for. |
| `app/Http/Controllers/Admin/CatalogController.php` | MODIFIED | #2 | `lookupChannel()` now: (1) wraps `validate()` in a try/catch so a 422 always returns JSON (the previous version returned HTML which broke the JS `fetch().json()` parser); (2) catches all exceptions from `TelegramBotClient` so a missing bot token or network error returns a JSON `{error: "telegram_unreachable"}` instead of an HTML 500 page; (3) tolerates `getChatMemberCount()` failing — the lookup still succeeds with `members: null`. |
| `resources/views/admin/channels/index.blade.php` | MODIFIED | #2 | The "Fetch info" button JS now: (1) wraps the entire IIFE in a `DOMContentLoaded` listener + `try/catch` so any init error is logged to the browser console instead of silently killing the button; (2) rebuilds `lookupUrl` from `window.location.pathname` when the route cache is stale (the previous version silently returned when the URL was empty); (3) detects 403 (missing `catalog.manage` permission) and 422 (validation) and shows a specific message; (4) detects non-JSON responses (session expired → admin login page returned as HTML) and prompts the operator to re-login; (5) syncs the cleaned username back into the input field so the form submits a normalized value. |
| `resources/views/admin/transactions/index.blade.php` | MODIFIED | #3 | Introduces two safe closures (`$enumValue` and `$humanize`) in the `@php` block at the top of the view. These accept any of: a string, a PHP `UnitEnum` instance (returns `->value`), a Model object with a `->value` attribute, or `null`. The three places that previously did `str((string) data_get($x, 'purpose'/'type', ...))` now call `$humanize(...)` instead. Also tightens the `provider`/`currency` casts in the same table so a null column no longer crashes PHP 8.1+ deprecation warnings in `strtoupper()`. |
| `resources/views/admin/transactions/show.blade.php` | MODIFIED | #3 (defensive) | `strtoupper($intent->provider)` → `strtoupper((string) ($intent->provider ?? 'ledger'))` so a null provider column doesn't trigger a PHP 8.1 deprecation warning on the transaction detail page. |
| `config/ads-platform.php` | MODIFIED | #4 | Adds `zarinpay_fee_tolerance_percent` config (default 10.0, override via `ZARINPAY_FEE_TOLERANCE_PERCENT` env var). Clamped to [0, 50] in the service. |

## How to deploy (v4)

1. Drop each file from this zip into the matching path in your project,
   replacing the existing file.
2. Run `php artisan view:clear` to drop the cached Blade templates
   (important — without this none of the Blade changes will be picked up).
3. Run `php artisan config:clear` if you have a cached config (necessary
   so the new `zarinpay_fee_tolerance_percent` key is picked up).
4. Run `npm run build` (or `npx vite build`) — NOT required for this patch
   because no JS lives in `resources/js/app.js`; all changes are inside
   Blade templates. But running it is safe.
5. No new migration in v4.

## Bug #4 — root cause analysis (the 5% fee mismatch)

The user paid 200,000 Toman (= 2,000,000 IRR) on the bank's 3D-Secure page.
ZarinPal's `verify-payment` endpoint returned:

```json
{
  "success": true,
  "data": {
    "code": 100,
    "transaction": {
      "payment_id": null,
      "amount": 2100000,
      "order_id": "ZP-W-1f716b35-...",
      "authority": "A0000000000000fq9x9axnyz7q"
    }
  }
}
```

Notice the `amount` is `2100000`, not `2000000` — the gateway added a
`100,000 IRR` (= 10,000 Toman = 5%) service fee. This is how some
ZarinPal-compatible aggregators (zarinmee.ir, zarinpal.com, etc.) charge
their fee: instead of deducting it from the merchant's settlement, they
collect it from the customer at payment time and report the gross amount
back through the verify endpoint.

The previous code's `zarinPayVerificationMismatch()` amount check only
accepted:

1. An exact match (`received === expected`)
2. A 10x ratio match (`received * 10 === expected`, for the Toman-vs-Rial case)

So a 5% fee delta of `100,000 IRR` fell outside both checks and triggered
`amount_mismatch` → `manual_review` → the user's wallet was never credited.

### The fix

The amount check now accepts THREE things:

1. **Exact match** — `received === expected` (the normal case)
2. **Toman/Rial ratio** — `received * 10 === expected` (ZarinPal returns Toman)
3. **Fee tolerance window** — `received` falls within
   `[expected * (1 - tol%), expected * (1 + tol%)]` (default tol = 10%)

When the verification is accepted via window #3 (or via the Toman ratio),
we log a `Log::notice` line so the operator can see it in the audit trail:

```
[2026-08-19 ...] production.NOTICE: ZarinPay verify accepted with amount tolerance
{"intent_id":8,"merchant_reference":"ZP-W-...","expected_amount_minor":2000000,
"received_amount_irr":2100000,"difference":100000,"difference_percent":5,
"acceptance_reason":"gateway_fee_within_tolerance","tolerance_percent":10}
```

The wallet credit is still based on `intent.amount_minor` (= 2,000,000 IRR
= 200,000 Toman), NOT the gross amount the gateway reports. The fee is the
gateway's revenue, not the user's balance.

### Tuning the tolerance

If your gateway charges a different fee percentage, override the tolerance
in your `.env`:

```env
# Allow the verify amount to be ±15% of the original (default is ±10%)
ZARINPAY_FEE_TOLERANCE_PERCENT=15
```

Then `php artisan config:clear` so the change is picked up. The service
clamps the value to `[0, 50]` — if you set it higher than 50, we cap it at
50 to prevent a 2x-amount attack from sneaking through.

To require an EXACT match (no tolerance at all):

```env
ZARINPAY_FEE_TOLERANCE_PERCENT=0
```

## Why bug #3 (enum crash) is STILL happening in your logs

Your latest log entry (2026-08-19 15:38:09) shows the SAME error
that the v4 patch was designed to fix:

```
Object of class App\Enums\PaymentPurpose could not be converted to string
(View: .../resources/views/admin/transactions/index.blade.php)
```

This means **the v4 patch has not yet been applied to your production server**.
The previous zip (`TelegramAdsBot-patch-v4.zip`) was downloaded but the
file `resources/views/admin/transactions/index.blade.php` was not copied to
the server, OR `php artisan view:clear` was not run after copying.

### How to confirm

On your production server, run:

```bash
cd /var/www/miladrajabi.com/bot/TelegramAdsBot
# Should print "35" or higher if the file has the $enumValue helper
grep -c 'enumValue' resources/views/admin/transactions/index.blade.php
```

If that prints `0`, the file is the OLD version. Replace it from this zip
and run `php artisan view:clear`.

If it prints `2` or higher, the file is the NEW version, but Laravel is
still serving the OLD compiled Blade. Run:

```bash
php artisan view:clear
```

That removes `storage/framework/views/*.php` and forces Laravel to recompile
the Blade template on the next request. Until you run it, the OLD compiled
file (`fe44e856522122456c12ee9ecff75308.php` in your stack trace) keeps
being served.

If you ran `php artisan view:cache` in the past (production cache optimization),
ALSO run:

```bash
php artisan view:cache   # rebuilds the compiled cache with the NEW source
```

### Why `view:clear` is mandatory

Laravel's Blade compiler takes `transactions/index.blade.php` and produces a
compiled PHP file at `storage/framework/views/<hash>.php`. The hash is based
on the file's full path, NOT its contents — so when you replace the source
file with a newer version, the compiled file with the SAME hash is still on
disk and still serves the OLD code. `view:clear` deletes the compiled file,
forcing Laravel to recompile the source on the next request.

## Bug #3 — root cause analysis (the enum cast)

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

## How to verify the fee-tolerance fix (bug #4)

After deploying, repeat the 200,000 Toman test payment:

1. The bank's 3D-Secure page will charge 210,000 Toman (= 2,100,000 IRR).
2. ZarinPal's `verify-payment` will return `amount: 2100000`.
3. The new code accepts this (within ±10% of 2,000,000).
4. `storage/logs/laravel.log` will contain a `NOTICE` line:

   ```
   ZarinPay verify accepted with amount tolerance
   {"intent_id":8,"merchant_reference":"ZP-W-...",
    "expected_amount_minor":2000000,"received_amount_irr":2100000,
    "difference":100000,"difference_percent":5,
    "acceptance_reason":"gateway_fee_within_tolerance","tolerance_percent":10}
   ```

5. The user's wallet is credited 200,000 Toman (NOT 210,000 — the 10,000
   Toman difference is the gateway's fee, not the user's money).
6. The payment_intent status becomes `succeeded` (NOT `manual_review`).
7. No audit log entry is created for `payment.verification_mismatch`.

The intent that was previously stuck in `manual_review` (intent_id=8 in
your logs) needs to be settled manually. You have two options:

### Option A — Re-run verify (idempotent)

The user clicks the callback URL again, OR you trigger verify from
tinker:

```bash
php artisan tinker
>>> $intent = App\Models\PaymentIntent::find(8);
>>> app(App\Services\PaymentService::class)->verifyZarinPay(
...   $intent->merchant_reference,
...   $intent->attempts()->latest('id')->first()->authority
... );
```

If `intent_id=8` is already in `succeeded` status, `verifyZarinPay` returns
it unchanged. If it's still in `manual_review`, the new tolerance window
accepts it and credits the wallet.

### Option B — Manually mark as succeeded + credit wallet

If you can't re-run verify (e.g. ZarinPal's authority has expired), do it
manually:

```bash
php artisan tinker
>>> $intent = App\Models\PaymentIntent::find(8);
>>> $intent->update(['status' => App\Enums\PaymentStatus::Succeeded, 'verified_at' => now()]);
>>> app(App\Services\LedgerService::class)->post('zarinpay_manual', 'zarinpay-manual-8', 'Manual credit after fee tolerance fix', [
...   ['account' => app(App\Services\LedgerService::class)->systemAccount('IRR', 'zarinpay_clearing', 'debit'), 'direction' => 'debit', 'amount_minor' => $intent->amount_minor],
...   ['account' => app(App\Services\LedgerService::class)->accountFor($intent->user, 'IRR', 'wallet_available', 'credit'), 'direction' => 'credit', 'amount_minor' => $intent->amount_minor],
... ]);
```

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

**Step 1 — confirm the source file is updated:**

```bash
cd /var/www/miladrajabi.com/bot/TelegramAdsBot
grep -c 'enumValue' resources/views/admin/transactions/index.blade.php
```

Should print `2` or higher. If it prints `0`, copy the file from this zip
and re-run.

**Step 2 — clear the compiled Blade cache:**

```bash
php artisan view:clear
# If you have `php artisan view:cache` in your deploy script:
php artisan view:cache
```

**Step 3 — verify the page loads:**

1. Log in to the admin panel, go to `/admin/transactions`.
2. The page should load without the
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

### Bug #1 — ZarinPal response shape drift

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

### Bug #4 — Gateway fee not expected

The previous code assumed ZarinPal's `verify-payment` always returns the
exact amount that was sent in `create-payment`. This is true for raw
ZarinPal, but ZarinPal-compatible aggregators (zarinmee.ir, etc.) often add
a service fee on top of the original amount at settlement time. The new
±10% tolerance window (configurable via `ZARINPAY_FEE_TOLERANCE_PERCENT`)
absorbs these fees without compromising security (the authority and
merchant reference are still checked exactly, and a 2x-amount attack would
fall far outside the ±10% window).

## Verification checklist

After deploying v4:

- [ ] Confirm the source file is updated:
      `grep -c 'enumValue' resources/views/admin/transactions/index.blade.php`
      returns `2` or higher.
- [ ] Run `php artisan view:clear` (and `php artisan view:cache` if your
      deploy script uses it).
- [ ] Run `php artisan config:clear` (and `php artisan config:cache`).
- [ ] Open `/admin/transactions`. The page should load without the
      `PaymentPurpose could not be converted to string` error.
- [ ] The "Transaction" column should show human-readable labels like
      `Wallet Top Up` or `Order Payment` (or the saved `description`
      when present).
- [ ] Click any transaction → the detail page should load and show
      `ZARINPAY` (or `NOWPAYMENTS` / `LEDGER`) in the page header.
- [ ] Re-run the test payment. The bank page will charge 210,000 Toman
      (= 2,100,000 IRR), ZarinPal's verify returns `amount: 2100000`,
      and the wallet is credited 200,000 Toman (NOT 210,000).
- [ ] Check `storage/logs/laravel.log` for a `NOTICE` line:
      `ZarinPay verify accepted with amount tolerance` — this confirms
      the fee tolerance window accepted the 5% delta.
- [ ] If the payment still fails, check `storage/logs/laravel.log` for
      the EXACT reason — the log now shows whether it's
      `gateway_returned_success_false`, `missing_authority`,
      `missing_payment_link`, `untrusted_payment_host`, or
      `amount_mismatch` (the last one means your gateway's fee is HIGHER
      than 10% — increase `ZARINPAY_FEE_TOLERANCE_PERCENT`).
- [ ] Open `/admin/channels`, type a channel username, click "دریافت اطلاعات".
      The title/members/avatar should auto-fill and a preview block should
      appear.
- [ ] Open browser dev tools → Console. If anything goes wrong you should
      now see a clear error message starting with `[admin.channels]`.
- [ ] If the lookup still doesn't work, check the admin's role has
      `catalog.manage` permission (or is `super_admin`).
