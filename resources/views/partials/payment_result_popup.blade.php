{{--
    Payment result popup partial.

    Include this anywhere the user can land after a payment flow
    (ZarinPay callback, NOWPayments redirect, manual top-up, etc.):
        @include('partials.payment-result-popup')

    It renders ONLY when a flash message (success / error / warning) is set
    AND the controller explicitly opts in by setting `payment_popup`:
        return redirect()->route('...')->with('success', '...')
            ->with('payment_popup', true);

    Non-payment flashes (e.g. "order saved, pick a payment method") still
    show through the regular <x-flash /> banner at the top of the page,
    but do NOT trigger this intrusive modal — which previously titled every
    success "پرداخت موفق / Payment successful" even when no payment had
    happened, contradicting the order status "در انتظار پرداخت".

    Dismissable via:
        • Click anywhere
        • Touch anywhere
        • Pressing Escape
        • Auto-dismiss after 8 seconds
--}}
@php
    $isFa = app()->isLocale('fa');
@endphp
@if(session('payment_popup') && (session('success') || session('error') || session('warning')))
<div class="pay-result-popup" data-pay-result-popup role="alertdialog" aria-live="assertive" style="position:fixed; inset:0; display:flex; align-items:center; justify-content:center; z-index:9999; background:rgba(15,23,42,.55); padding:16px;" hidden>
    <div class="pay-result-card" style="max-width:480px; width:100%; background:#fff; border-radius:16px; padding:24px 20px; box-shadow:0 24px 48px rgba(0,0,0,.18); text-align:center; position:relative;">
        <button type="button" class="pay-result-close" data-pay-result-close aria-label="{{ $isFa ? 'بستن' : 'Close' }}" style="position:absolute; top:8px; inset-inline-end:12px; background:none; border:0; color:#64748b; font-size:24px; cursor:pointer; line-height:1;">×</button>
        <div style="font-size:48px; margin-bottom:8px;">
            @if(session('success'))<span style="color:#16a34a;">✓</span>@elseif(session('error'))<span style="color:#dc2626;">!</span>@else<span style="color:#d97706;">⏳</span>@endif
        </div>
        @if(session('success'))<h3 style="margin:0 0 8px; color:#16a34a; font-size:18px; font-weight:700;">{{ $isFa ? 'پرداخت موفق' : 'Payment successful' }}</h3>
        <p style="margin:0; color:#334155; line-height:1.6;">{{ session('success') }}</p>
        @elseif(session('error'))<h3 style="margin:0 0 8px; color:#dc2626; font-size:18px; font-weight:700;">{{ $isFa ? 'پرداخت ناموفق' : 'Payment failed' }}</h3>
        <p style="margin:0; color:#334155; line-height:1.6;">{{ session('error') }}</p>
        @else<h3 style="margin:0 0 8px; color:#d97706; font-size:18px; font-weight:700;">{{ $isFa ? 'در حال پردازش' : 'Processing' }}</h3>
        <p style="margin:0; color:#334155; line-height:1.6;">{{ session('warning') }}</p>
        @endif
        <button type="button" class="btn btn-primary" data-pay-result-close style="margin-top:16px; min-width:120px;">{{ $isFa ? 'متوجه شدم' : 'OK' }}</button>
        <p style="margin:8px 0 0; font-size:12px; color:#94a3b8;">{{ $isFa ? 'برای بستن، هر جای صفحه را لمس کنید.' : 'Tap anywhere to close.' }}</p>
    </div>
</div>
<script>
(function () {
    var popup = document.querySelector('[data-pay-result-popup]');
    if (!popup) return;
    popup.hidden = false;

    function dismiss() {
        popup.style.display = 'none';
        try { sessionStorage.setItem('pay-result-dismissed', '1'); } catch (_) {}
    }

    // Click anywhere on the popup (including the backdrop) closes it.
    popup.addEventListener('click', dismiss);

    // Touch anywhere also closes it.
    popup.addEventListener('touchstart', dismiss, { passive: true });

    // Close button inside the card.
    document.querySelectorAll('[data-pay-result-close]').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); dismiss(); });
    });

    // Escape key closes the popup.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') dismiss();
    });

    // Auto-dismiss after 8 seconds.
    setTimeout(dismiss, 8000);
})();
</script>
@endif
