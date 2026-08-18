<?php

namespace App\Http\Controllers\MiniApp;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Services\PaymentService;
use App\Services\Payments\NowPaymentsClient;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PaymentController extends Controller
{
    public function deposit(
        Request $request,
        PaymentService $payments,
        NowPaymentsClient $nowPayments,
        PricingService $pricing,
    ): RedirectResponse {
        $provider = $request->validate(['provider' => ['required', 'in:zarinpay,nowpayments']])['provider'];

        return $provider === 'zarinpay'
            ? $this->topUpWithZarinPay($request, $payments)
            : $this->topUpWithNowPayments($request, $nowPayments, $pricing);
    }

    public function payOrderFromWallet(Request $request, Order $campaign, PaymentService $payments): RedirectResponse
    {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        $this->assertQuoteActive($order);
        $payments->fundOrderFromWallet($request->user(), $order, 'wallet-order:'.$order->public_id);

        return redirect()->route('app.campaigns.show', $order)->with('success', 'مبلغ رزرو شد و سفارش وارد بررسی پشتیبانی شد.');
    }

    public function topUpWithZarinPay(Request $request, PaymentService $payments): RedirectResponse
    {
        $this->assertProviderEnabled('zarinpay');
        $data = $request->validate([
            'amount_toman' => ['required', 'integer', 'min:10000', 'max:10000000000'],
            'funding_card_id' => ['nullable', 'integer'],
        ]);
        $this->assertRialKyc($request);
        $card = $this->approvedCard($request, $data['funding_card_id'] ?? null);
        $intent = $payments->createZarinPayIntent(
            $request->user(),
            PaymentPurpose::WalletTopUp,
            (int) $data['amount_toman'] * 10,
            route('payments.zarinpay.callback'),
            'ZP-W-'.Str::uuid(),
            description: 'افزایش موجودی کیف پول Ads Platform',
        );
        $intent->update(['metadata' => [...($intent->metadata ?? []), 'expected_funding_card_id' => $card->id, 'expected_card_last4' => $card->last4, 'payer_pan_verified_by_gateway' => false]]);

        return $this->redirectToZarinPay($intent);
    }

    public function payOrderWithZarinPay(Request $request, Order $campaign, PaymentService $payments): RedirectResponse
    {
        $this->assertProviderEnabled('zarinpay');
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        abort_if($order->payment_status->value === 'paid', 422, 'این سفارش قبلاً پرداخت شده است.');
        $this->assertQuoteActive($order);
        $this->assertRialKyc($request);
        $data = $request->validate(['funding_card_id' => ['nullable', 'integer']]);
        $card = $this->approvedCard($request, $data['funding_card_id'] ?? null);

        $intent = $payments->createZarinPayIntent(
            $request->user(),
            PaymentPurpose::OrderPayment,
            $order->total_irr,
            route('payments.zarinpay.callback'),
            'ZP-O-'.Str::uuid(),
            order: $order,
            description: 'پرداخت سفارش '.$order->public_id,
        );
        $intent->update(['metadata' => [...($intent->metadata ?? []), 'expected_funding_card_id' => $card->id, 'expected_card_last4' => $card->last4, 'payer_pan_verified_by_gateway' => false]]);

        return $this->redirectToZarinPay($intent);
    }

    public function zarinPayCallback(Request $request, PaymentService $payments): RedirectResponse
    {
        $merchantReference = (string) ($request->input('order_id') ?? $request->input('merchant_reference') ?? '');
        $authority = (string) ($request->input('authority') ?? $request->input('Authority') ?? '');

        try {
            $intent = $payments->verifyZarinPay($merchantReference, $authority);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('app.wallet.index')->with('error', 'تأیید پرداخت انجام نشد. اگر مبلغ کسر شده، از تکرار پرداخت خودداری و با پشتیبانی تماس بگیرید.');
        }

        $route = $intent->order ? route('app.campaigns.show', $intent->order) : route('app.wallet.index');

        if ($intent->status !== PaymentStatus::Succeeded) {
            $message = $intent->status === PaymentStatus::ManualReview
                ? 'نتیجه پرداخت با اطلاعات سفارش تطبیق نداشت و برای بررسی مالی نگه داشته شد. پرداخت را تکرار نکنید.'
                : 'پرداخت توسط درگاه تأیید نشد. اگر مبلغی کسر شده است، پرداخت را تکرار نکنید و با پشتیبانی تماس بگیرید.';

            return redirect($route)->with('error', $message);
        }

        return redirect($route)->with('success', 'پرداخت به‌صورت سروربه‌سرور تأیید و در دفتر مالی ثبت شد.');
    }

    public function zarinPayMock(PaymentIntent $intent): View
    {
        abort_unless(app()->isLocal() && config('services.zarinpay.mock'), 404);
        $intent->load('attempts');

        return view('app.payments.mock', compact('intent'));
    }

    public function resume(Request $request, PaymentIntent $payment): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->getKey(), 404);
        $payment->load(['order', 'attempts']);

        if ($payment->status === PaymentStatus::Succeeded) {
            return $this->returnToPaymentSubject($payment)
                ->with('success', 'این پرداخت قبلاً با موفقیت تأیید شده است.');
        }

        if (! in_array($payment->status, [PaymentStatus::Created, PaymentStatus::Pending, PaymentStatus::Verifying], true)) {
            return $this->returnToPaymentSubject($payment)
                ->with('error', 'این پرداخت دیگر قابل ادامه نیست؛ در صورت کسر وجه با پشتیبانی تماس بگیرید.');
        }

        if ($payment->expires_at?->isPast()) {
            $payment->update(['status' => PaymentStatus::Expired]);

            return $this->returnToPaymentSubject($payment)
                ->with('error', 'مهلت این پرداخت تمام شده است؛ یک پرداخت تازه ایجاد کنید.');
        }

        if ($payment->provider === 'zarinpay' && app()->isLocal() && config('services.zarinpay.mock')) {
            return redirect()->route('payments.zarinpay.mock', $payment);
        }

        $url = $payment->attempts->sortByDesc('id')->first()?->redirect_url;
        if (is_string($url) && $this->isTrustedProviderRedirect($payment->provider, $url)) {
            return redirect()->away($url);
        }

        return $this->returnToPaymentSubject($payment)
            ->with('error', 'لینک پرداخت در دسترس نیست؛ پرداخت جدید بسازید یا با پشتیبانی تماس بگیرید.');
    }

    public function confirmZarinPayMock(PaymentIntent $intent, PaymentService $payments): RedirectResponse
    {
        abort_unless(app()->isLocal() && config('services.zarinpay.mock'), 404);
        $payments->settleSuccessfulIntent($intent, 'mock:'.$intent->merchant_reference, [
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'mock' => true,
        ]);

        return redirect()->route($intent->order_id ? 'app.campaigns.show' : 'app.wallet.index', $intent->order_id ? [$intent->order] : [])
            ->with('success', 'پرداخت آزمایشی تأیید شد.');
    }

    public function cancelZarinPayMock(PaymentIntent $intent): RedirectResponse
    {
        abort_unless(app()->isLocal() && config('services.zarinpay.mock'), 404);

        DB::transaction(function () use ($intent): void {
            $intent->refresh();
            if ($intent->status === PaymentStatus::Succeeded) {
                return;
            }

            $intent->update(['status' => PaymentStatus::Failed]);
            $intent->attempts()->create([
                'provider_reference' => 'mock:cancelled',
                'verify_code' => 'cancelled',
                'provider_response' => ['mock' => true, 'result' => 'cancelled'],
            ]);

            if ($intent->order && $intent->order->payment_status->value === 'pending') {
                $intent->order->update(['payment_status' => 'unfunded', 'funding_mode' => null]);
            }
        });

        return $this->returnToPaymentSubject($intent->fresh('order'))
            ->with('error', 'پرداخت آزمایشی ناموفق ثبت شد.');
    }

    public function payOrderWithNowPayments(Request $request, Order $campaign, NowPaymentsClient $nowPayments): RedirectResponse
    {
        $this->assertProviderEnabled('nowpayments');
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        abort_if($order->payment_status->value === 'paid', 422, 'این سفارش قبلاً پرداخت شده است.');
        abort_unless($order->status->value === 'awaiting_payment' && in_array($order->payment_status->value, ['unfunded', 'pending'], true), 422, 'این سفارش در وضعیت پرداخت‌پذیر نیست.');
        abort_if($order->paymentIntents()->whereIn('status', ['pending', 'verifying', 'succeeded'])->exists(), 422, 'برای این سفارش یک پرداخت فعال وجود دارد.');
        $this->assertQuoteActive($order);

        $intent = DB::transaction(function () use ($request, $order): PaymentIntent {
            return PaymentIntent::create([
                'user_id' => $request->user()->getKey(),
                'order_id' => $order->getKey(),
                'purpose' => PaymentPurpose::OrderPayment,
                'provider' => 'nowpayments',
                'merchant_reference' => 'NP-O-'.Str::uuid(),
                'amount_minor' => $order->total_irr,
                'currency' => 'IRR',
                'status' => PaymentStatus::Created,
                'expires_at' => now()->addDay(),
                'metadata' => ['usd_amount' => (float) $order->usd_amount],
            ]);
        });

        try {
            $invoice = $nowPayments->createInvoice(
                (float) $order->usd_amount,
                $intent->merchant_reference,
                'Order '.$order->public_id,
                route('app.campaigns.show', ['campaign' => $order, 'payment' => 'pending']),
                route('app.campaigns.show', ['campaign' => $order, 'payment' => 'cancelled']),
            );
            $invoiceUrl = $nowPayments->trustedInvoiceUrl($invoice);
            $intent->attempts()->create([
                'provider_reference' => (string) ($invoice['id'] ?? ''),
                'redirect_url' => $invoiceUrl,
                'provider_response' => $invoice,
            ]);
            $intent->update(['status' => PaymentStatus::Pending]);
            $order->update(['payment_status' => 'pending', 'funding_mode' => 'nowpayments_direct']);
        } catch (Throwable $exception) {
            $intent->update(['status' => PaymentStatus::Failed, 'metadata' => [...($intent->metadata ?? []), 'error' => Str::limit($exception->getMessage(), 300, '')]]);
            throw $exception;
        }

        return redirect()->away($invoiceUrl);
    }

    public function topUpWithNowPayments(
        Request $request,
        NowPaymentsClient $nowPayments,
        PricingService $pricing,
    ): RedirectResponse {
        $this->assertProviderEnabled('nowpayments');
        $data = $request->validate(['amount_usd' => ['required', 'numeric', 'min:5', 'max:100000']]);
        $usdAmount = round((float) $data['amount_usd'], 2);
        $intent = PaymentIntent::create([
            'user_id' => $request->user()->getKey(),
            'purpose' => PaymentPurpose::WalletTopUp,
            'provider' => 'nowpayments',
            'merchant_reference' => 'NP-W-'.Str::uuid(),
            'amount_minor' => $pricing->usdToIrr($usdAmount),
            'currency' => 'IRR',
            'status' => PaymentStatus::Created,
            'expires_at' => now()->addDay(),
            'metadata' => ['usd_amount' => $usdAmount],
        ]);

        try {
            $invoice = $nowPayments->createInvoice(
                $usdAmount,
                $intent->merchant_reference,
                'Wallet top-up '.$intent->public_id,
                route('app.wallet.index', ['payment' => 'pending']),
                route('app.wallet.index', ['payment' => 'cancelled']),
            );
            $invoiceUrl = $nowPayments->trustedInvoiceUrl($invoice);
            $intent->attempts()->create([
                'provider_reference' => (string) ($invoice['id'] ?? ''),
                'redirect_url' => $invoiceUrl,
                'provider_response' => $invoice,
            ]);
            $intent->update(['status' => PaymentStatus::Pending]);
        } catch (Throwable $exception) {
            $intent->update(['status' => PaymentStatus::Failed]);
            throw $exception;
        }

        return redirect()->away($invoiceUrl);
    }

    public function nowPaymentsIpn(Request $request, NowPaymentsClient $nowPayments, PaymentService $payments): JsonResponse
    {
        $payload = $request->json()->all();
        $signature = $request->header('x-nowpayments-sig');
        $valid = $nowPayments->validIpn($payload, $signature);
        $eventKey = 'nowpayments:'.hash('sha256', json_encode([$payload['payment_id'] ?? $payload['invoice_id'] ?? null, $payload['payment_status'] ?? null, $payload['updated_at'] ?? null]));
        $redactedPayload = json_encode($this->redactIpn($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! $valid) {
            DB::table('payment_webhook_events')->insertOrIgnore([
                'provider' => 'nowpayments',
                'event_key' => $eventKey,
                'signature_valid' => false,
                'payload_redacted' => $redactedPayload,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            abort(401, 'Invalid IPN signature.');
        }

        $existingEvent = DB::table('payment_webhook_events')->where('event_key', $eventKey)->first();
        if ($existingEvent?->processed_at) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        if ($existingEvent) {
            DB::table('payment_webhook_events')->where('event_key', $eventKey)->update([
                'signature_valid' => true,
                'payload_redacted' => $redactedPayload,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('payment_webhook_events')->insertOrIgnore([
                'provider' => 'nowpayments',
                'event_key' => $eventKey,
                'signature_valid' => true,
                'payload_redacted' => $redactedPayload,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $intent = PaymentIntent::where('provider', 'nowpayments')->where('merchant_reference', (string) ($payload['order_id'] ?? ''))->firstOrFail();
        $status = strtolower((string) ($payload['payment_status'] ?? ''));

        if ($intent->status === PaymentStatus::Succeeded
            && ! in_array($status, ['finished', 'refunded'], true)) {
            DB::table('payment_webhook_events')->where('event_key', $eventKey)->update(['processed_at' => now(), 'updated_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => 'terminal_payment']);
        }

        if ($status === 'finished') {
            $expectedUsd = (float) data_get($intent->metadata, 'usd_amount', 0);
            $hasPriceAmount = is_numeric($payload['price_amount'] ?? null);
            $receivedUsd = $hasPriceAmount ? (float) $payload['price_amount'] : 0.0;
            $priceCurrency = strtolower((string) ($payload['price_currency'] ?? ''));
            $invoiceReference = (string) ($payload['invoice_id'] ?? '');
            $knownInvoice = $invoiceReference !== ''
                && $intent->attempts()->where('provider_reference', $invoiceReference)->exists();
            if (! $hasPriceAmount
                || $expectedUsd <= 0
                || abs($receivedUsd - $expectedUsd) > 0.02
                || $priceCurrency !== 'usd'
                || ! $knownInvoice) {
                $intent->update(['status' => PaymentStatus::ManualReview]);
            } else {
                $payments->settleSuccessfulIntent($intent, (string) ($payload['payment_id'] ?? $payload['invoice_id'] ?? 'nowpayments'), [
                    'amount_minor' => $intent->amount_minor,
                    'currency' => $intent->currency,
                    'provider_payload' => $this->redactIpn($payload),
                ]);
            }
        } elseif ($status === 'partially_paid') {
            if ($intent->status !== PaymentStatus::Succeeded) {
                $intent->update(['status' => PaymentStatus::ManualReview]);
            }
        } elseif ($status === 'refunded') {
            $intent->update([
                'metadata' => [...($intent->metadata ?? []), 'provider_refund_reported_at' => now()->toIso8601String()],
            ]);
            if ($intent->order) {
                $intent->order->operatorTasks()->firstOrCreate([
                    'type' => 'reconcile_provider_refund',
                    'status' => 'open',
                ], ['context' => ['payment_intent_id' => $intent->id, 'provider' => 'nowpayments']]);
            }
        } elseif (in_array($status, ['failed', 'expired'], true) && $intent->status !== PaymentStatus::Succeeded) {
            $intent->update(['status' => PaymentStatus::Failed]);
            if ($intent->order && $intent->order->payment_status->value === 'pending') {
                $intent->order->update(['payment_status' => 'unfunded', 'funding_mode' => null]);
            }
        } else {
            if ($intent->status !== PaymentStatus::Succeeded) {
                $intent->update(['status' => PaymentStatus::Pending]);
            }
        }

        DB::table('payment_webhook_events')->where('event_key', $eventKey)->update(['processed_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function assertRialKyc(Request $request): void
    {
        if (! $request->user()->canUseRialPayments()) {
            throw ValidationException::withMessages([
                'payment' => 'پرداخت ریالی فقط پس از تأیید شماره همراه، احراز هویت و کارت بانکی فعال است.',
            ]);
        }
    }

    private function assertProviderEnabled(string $provider): void
    {
        $enabled = match ($provider) {
            'zarinpay' => (bool) config('services.zarinpay.enabled')
                || (app()->isLocal() && (bool) config('services.zarinpay.mock')),
            'nowpayments' => (bool) config('services.nowpayments.enabled'),
            default => false,
        };

        abort_unless($enabled, 503, 'این روش پرداخت در حال حاضر فعال نیست.');
    }

    private function assertQuoteActive(Order $order): void
    {
        if (! $order->quote_expires_at || $order->quote_expires_at->isPast()) {
            throw ValidationException::withMessages(['payment' => 'اعتبار قیمت این سفارش تمام شده است؛ ابتدا قیمت را به‌روزرسانی کنید.']);
        }
    }

    private function approvedCard(Request $request, ?int $cardId): \App\Models\FundingCard
    {
        $query = $request->user()->fundingCards()->where('status', 'approved');
        $card = $cardId ? $query->whereKey($cardId)->first() : $query->latest('verified_at')->first();

        if (! $card) {
            throw ValidationException::withMessages(['funding_card_id' => 'کارت بانکی تأییدشده پیدا نشد.']);
        }

        return $card;
    }

    private function redirectToZarinPay(PaymentIntent $intent): RedirectResponse
    {
        if (app()->isLocal() && config('services.zarinpay.mock')) {
            return redirect()->route('payments.zarinpay.mock', $intent);
        }

        $url = $intent->attempts()->latest()->value('redirect_url');
        abort_unless(is_string($url) && $this->isTrustedProviderRedirect('zarinpay', $url), 502, 'Payment gateway URL is invalid.');

        return redirect()->away($url);
    }

    private function returnToPaymentSubject(PaymentIntent $intent): RedirectResponse
    {
        return $intent->order_id
            ? redirect()->route('app.campaigns.show', $intent->order)
            : redirect()->route('app.wallet.index');
    }

    private function isTrustedProviderRedirect(string $provider, string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = match ($provider) {
            'zarinpay' => (array) config('services.zarinpay.payment_hosts', ['zarinmee.ir']),
            'nowpayments' => (array) config('services.nowpayments.invoice_hosts', ['nowpayments.io']),
            default => [],
        };
        $trustedHost = collect($allowedHosts)->contains(
            fn (mixed $allowed): bool => $host === strtolower((string) $allowed)
                || str_ends_with($host, '.'.strtolower((string) $allowed)),
        );

        return ($parts['scheme'] ?? null) === 'https'
            && $host !== ''
            && $trustedHost
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    /** @param array<string, mixed> $payload */
    private function redactIpn(array $payload): array
    {
        foreach (['pay_address', 'payout_address', 'payin_extra_id'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
