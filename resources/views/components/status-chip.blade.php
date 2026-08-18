@props(['value' => 'unknown', 'label' => null, 'tone' => null])
@php
    $raw = $value instanceof \BackedEnum ? $value->value : (string) ($value ?? 'unknown');
    $danger = ['telegram_rejected', 'cancelled_by_support', 'cancelled_by_user', 'rejected', 'rejected_permanent', 'failed', 'revoked', 'restricted'];
    $success = ['active', 'completed', 'telegram_approved', 'approved', 'paid', 'verified', 'rial_verified', 'succeeded'];
    $warning = ['changes_requested', 'pause_requested', 'resume_requested', 'manual_attention', 'pending', 'held_for_review', 'expired'];
    $info = ['support_review', 'queued_for_telegram', 'telegram_review', 'scheduled', 'submitted', 'under_review', 'processing', 'created'];
    $resolvedTone = $tone ?: (in_array($raw, $danger, true) ? 'danger' : (in_array($raw, $success, true) ? 'success' : (in_array($raw, $warning, true) ? 'warning' : (in_array($raw, $info, true) ? 'info' : 'neutral'))));
    $key = 'ui.status.' . $raw;
    $resolvedLabel = $label ?: (\Illuminate\Support\Facades\Lang::has($key) ? __($key) : str($raw)->replace('_', ' ')->title());
@endphp
<span {{ $attributes->class(['status-chip', 'status-' . $resolvedTone]) }}><span class="status-dot" aria-hidden="true"></span>{{ $resolvedLabel }}</span>
