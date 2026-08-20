<?php

namespace App\Http\Controllers\MiniApp;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CampaignRevision;
use App\Models\Order;
use App\Models\TargetCategory;
use App\Services\AuditLogger;
use App\Services\CampaignContentValidator;
use App\Services\CampaignTransitionService;
use App\Services\MiniAppNotifier;
use App\Services\PaymentService;
use App\Services\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
        ]);
        $orders = $request->user()->orders()
            ->with(['currentRevision', 'metrics' => fn ($query) => $query->latest('as_of_at')])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $term = trim((string) $filters['q']);
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->where('public_id', 'like', "%{$term}%")
                        ->orWhereHas('currentRevision', fn (Builder $revision) => $revision->where('internal_title', 'like', "%{$term}%"));
                });
            })
            ->latest()->paginate(12)->withQueryString();

        return view('app.campaigns.index', compact('orders'));
    }

    public function create(PricingService $pricing): View
    {
        $categories = TargetCategory::query()->where('is_active', true)
            ->with(['channels' => fn ($query) => $query->where('is_active', true)
                // Persian-language channels surface first inside every
                // category so the "کانال‌های پیشنهادی ایران" block stays at
                // the top of the picker. Featured channels pin above that.
                ->orderByRaw("CASE WHEN language = 'fa' THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN is_featured = 1 THEN 0 ELSE 1 END")
                ->orderByDesc('members_count')
                ->limit(30)])
            ->orderBy('sort_order')->get();
        // Suggested-channel catalogue ordered the same way for the flat list
        // rendered above the categories.
        $suggestedChannels = \App\Models\SuggestedChannel::query()->where('is_active', true)
            ->orderByRaw("CASE WHEN language = 'fa' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN is_featured = 1 THEN 0 ELSE 1 END")
            ->orderByDesc('members_count')
            ->limit(60)->get();
        $initial = $pricing->quote((int) config('ads-platform.minimum_order_irr', 1_000_000));
        $defaults = [
            'media_budget_toman' => intdiv($initial['media_budget_irr'], 10),
            'service_markup_percent' => $initial['service_markup_bps'] / 100,
            'impression_goal' => 10000,
        ];
        $quote = [
            'media_budget_toman' => $defaults['media_budget_toman'],
            'service_markup_percent' => $defaults['service_markup_percent'],
            'service_fee_toman' => intdiv($initial['service_fee_irr'], 10),
            'gateway_fee_toman' => intdiv($initial['gateway_fee_irr'], 10),
            'total_toman' => intdiv($initial['total_irr'], 10),
            'total_usd' => (float) $initial['usd_amount'],
            // Expose the live rates so the create.blade.php JS can convert
            // gram → toman in real-time on step 4 (CPM + media budget input).
            'usd_to_irr_rate' => (float) $initial['usd_to_irr_rate'],
            'gram_to_usd_rate' => (float) $initial['gram_to_usd_rate'],
            // Default media_budget_gram derived from the default toman value
            // so the input field has a sensible starting value.
            'media_budget_gram' => $initial['usd_to_irr_rate'] > 0 && $initial['gram_to_usd_rate'] > 0
                ? ($defaults['media_budget_toman'] * 10) / ($initial['usd_to_irr_rate'] * $initial['gram_to_usd_rate'])
                : 0,
        ];
        // Expose the effective minimum-order amount (in toman) so the create
        // view can render a client-side guard on the budget step: if the
        // gram→toman equivalent falls below this number, the wizard blocks
        // "Continue" with an inline error next to the budget field instead
        // of letting the user reach the submit step and only then learning
        // the order is below the platform minimum.
        $minimumOrderToman = intdiv((int) ($initial['minimum_order_irr'] ?? config('ads-platform.minimum_order_irr', 1_000_000)), 10);
        [$zarinPayEnabled, $nowPaymentsEnabled] = $this->paymentAvailability();

        return view('app.campaigns.create', compact(
            'categories', 'defaults', 'quote', 'zarinPayEnabled', 'nowPaymentsEnabled', 'suggestedChannels', 'minimumOrderToman',
        ));
    }

    public function store(Request $request, CampaignContentValidator $contentValidator, PricingService $pricing, MiniAppNotifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'internal_title' => ['required', 'string', 'max:120'],
            // ad_text now permits emoji; the only hard constraint is no line breaks.
            'ad_text' => ['required', 'string', 'max:160', 'not_regex:/\R/u'],
            // destination_type is no longer collected from the user — derived
            // from placement_type at runtime for backward compat with the schema.
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'placement_type' => ['required', Rule::in(['channel_posts', 'search_results', 'bot_messages'])],
            'impression_goal' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'frequency_cap' => ['nullable', 'integer', 'min:1', 'max:10'],
            'daily_view_limit_per_user' => ['required', 'integer', 'min:1', 'max:4'],
            'plan' => ['required', Rule::in(['standard', 'competitive'])],
            'language' => ['nullable', Rule::in(['fa', 'en'])],
            'funding_mode' => ['nullable', Rule::in(['wallet', 'zarinpay', 'nowpayments'])],
            'cpm_gram' => ['required', 'numeric', 'min:0.1', 'max:1000000'],
            'media_budget_toman' => ['required', 'integer', 'min:10000', 'max:10000000000'],
            // New visible gram input on step 4. The hidden media_budget_toman
            // is what the backend actually uses; we accept media_budget_gram
            // just so the form submission doesn't 422 on the new field.
            'media_budget_gram' => ['nullable', 'numeric', 'min:0'],
            'planned_start_at' => ['nullable', 'date', 'after:now'],
            'target_channel_ids' => ['required', 'array', 'min:1', 'max:100'],
            'target_channel_ids.*' => ['string', 'max:128'],
            'manual_channels' => ['nullable', 'string', 'max:5000'],
            'ad_media' => ['nullable', 'file', 'max:51200', 'mimes:jpg,jpeg,png,webp,mp4,mov,webm'],
            'search_keywords' => ['nullable', 'array', 'max:30'],
            'search_keywords.*' => ['string', 'min:4', 'max:64'],
            'terms_accepted' => ['accepted'],
        ], [
            'ad_text.max' => 'متن تبلیغ حداکثر 160 نویسه است.',
            'ad_text.not_regex' => 'شکست خط در متن تبلیغ مجاز نیست.',
            'terms_accepted.accepted' => 'پذیرش قوانین ثبت سفارش الزامی است.',
            'target_channel_ids.required' => 'انتخاب حداقل یک کانال یا ربات هدف الزامی است.',
            'target_channel_ids.min' => 'انتخاب حداقل یک کانال یا ربات هدف الزامی است.',
            'search_keywords.*.min' => 'هر کلیدواژه جستجو باید حداقل 4 نویسه باشد.',
            'daily_view_limit_per_user.required' => 'انتخاب محدودیت بازدید روزانه برای هر کاربر الزامی است.',
        ]);

        // placement_type → destination_type derivation (kept for schema compat).
        $destinationTypeMap = [
            'channel_posts' => 'channel',
            'bot_messages' => 'bot',
            'search_results' => 'channel',
        ];
        $data['destination_type'] = $destinationTypeMap[$data['placement_type']] ?? 'channel';

        // ── Field-level content validation ───────────────────────────────
        // Each check is attached to the field it belongs to so the wizard
        // can render the error INLINE next to that field (and jump back to
        // the correct step) instead of dumping a generic notice at the top
        // of the page after the user has already walked through every step.
        // The same checks run client-side in resources/js/app.js — keep the
        // messages in sync.
        $adTextErrors = $contentValidator->adTextErrors($data['ad_text']);
        if ($adTextErrors !== []) {
            return back()->withInput()->withErrors(['ad_text' => implode(' ', $adTextErrors)]);
        }
        $destinationErrors = $contentValidator->destinationUrlErrors($data['destination_url']);
        if ($destinationErrors !== []) {
            return back()->withInput()->withErrors(['destination_url' => implode(' ', $destinationErrors)]);
        }
        $riskFlags = $contentValidator->riskFlags($data['ad_text'], $data['destination_url']);

        // Persist the optional ad media (image or video) on the private disk.
        // We never trust the user-supplied filename on disk — we hash it.
        $adMediaPath = null;
        $adMediaType = null;
        if ($request->hasFile('ad_media') && $request->file('ad_media')->isValid()) {
            $file = $request->file('ad_media');
            $adMediaType = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
            $adMediaPath = 'ad-media/'.Str::random(40).'.'.$file->getClientOriginalExtension();
            $file->storeAs('local', $adMediaPath);
        }

        $mediaBudgetIrr = (int) $data['media_budget_toman'] * 10;
        $quote = $pricing->quote($mediaBudgetIrr);
        if ($mediaBudgetIrr < $quote['minimum_order_irr']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'media_budget_toman' => 'بودجه از حداقل سفارش فعال کمتر است.',
            ]);
        }

        $quoteTtlMinutes = $pricing->quoteTtlMinutes();
        $order = DB::transaction(function () use ($request, $data, $quote, $riskFlags, $quoteTtlMinutes): Order {
            $order = Order::create([
                'user_id' => $request->user()->getKey(),
                'status' => OrderStatus::AwaitingPayment,
                'funding_mode' => $data['funding_mode'] ?? null,
                'media_budget_irr' => $quote['media_budget_irr'],
                'service_markup_bps' => $quote['service_markup_bps'],
                'service_fee_irr' => $quote['service_fee_irr'],
                'gateway_fee_irr' => $quote['gateway_fee_irr'],
                'total_irr' => $quote['total_irr'],
                'usd_amount' => $quote['usd_amount'],
                'gram_amount' => $quote['gram_amount'],
                'usd_to_irr_rate' => $quote['usd_to_irr_rate'],
                'gram_to_usd_rate' => $quote['gram_to_usd_rate'],
                'conversion_margin_bps' => $quote['conversion_margin_bps'],
                'rate_source' => $quote['rate_source'],
                'quoted_at' => now(),
                'quote_expires_at' => now()->addMinutes($quoteTtlMinutes),
                'planned_start_at' => $data['planned_start_at'] ?? null,
            ]);

            $revision = $order->revisions()->create([
                'revision_no' => 1,
                'internal_title' => trim($data['internal_title']),
                'ad_text' => trim($data['ad_text']),
                'destination_type' => $data['destination_type'],
                'destination_url' => $data['destination_url'],
                'placement_type' => $data['placement_type'],
                'targeting_payload' => ['mode' => $data['placement_type'], 'automated_content_flags' => $riskFlags],
                'impression_goal' => $data['impression_goal'] ?? null,
                'frequency_cap' => $data['frequency_cap'] ?? null,
                'daily_view_limit_per_user' => (int) $data['daily_view_limit_per_user'],
                'plan' => $data['plan'],
                'cpm_gram' => $data['cpm_gram'],
                'language' => $data['language'] ?? $request->user()->locale,
                'ad_media_path' => $adMediaPath,
                'ad_media_type' => $adMediaType,
                'ad_media_disk' => $adMediaPath ? 'local' : null,
                'search_keywords' => $data['search_keywords'] ?? null,
            ]);

            $this->storeTargets($revision, $data);
            $order->update(['current_revision_id' => $revision->getKey()]);
            $order->statusEvents()->create([
                'from_status' => OrderStatus::Draft->value,
                'to_status' => OrderStatus::AwaitingPayment->value,
                'actor_type' => $request->user()->getMorphClass(),
                'actor_id' => $request->user()->getKey(),
                'correlation_id' => (string) Str::uuid(),
            ]);

            if ($riskFlags !== []) {
                $order->operatorTasks()->create([
                    'type' => 'content_risk_review',
                    'status' => 'open',
                    'context' => ['flags' => $riskFlags, 'source' => 'heuristic_v1'],
                ]);
            }

            $policies = DB::table('policy_versions')
                ->whereIn('type', ['service_terms', 'ads_policy', 'telegram_rejection_policy'])
                ->where('is_active', true)->get();
            foreach ($policies as $policy) {
                DB::table('policy_acceptances')->insertOrIgnore([
                    'user_id' => $request->user()->getKey(),
                    'policy_version_id' => $policy->id,
                    'order_id' => $order->getKey(),
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                    'accepted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $order;
        });

        // Push a Telegram notification with an "Open Mini App" button so
        // the user can jump straight to the payment step.
        $notifier->orderCreated($order);

        return redirect()->route('app.campaigns.show', $order)->with('success', 'سفارش ذخیره شد؛ روش پرداخت را انتخاب کنید.');
    }

    public function show(Request $request, Order $campaign, PaymentService $payments, PricingService $pricing): View
    {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        $order->load([
            'currentRevision.targets',
            'currentRevision.telegramSubmissions',
            'metrics' => fn ($q) => $q->orderBy('as_of_at'),
            'statusEvents' => fn ($q) => $q->orderBy('created_at'),
        ]);
        $user = $request->user();
        $fundingCards = $user->fundingCards()->where('status', 'approved')->latest()->get();
        $canUseRialPayments = $user->canUseRialPayments();
        [$zarinPayEnabled, $nowPaymentsEnabled] = $this->paymentAvailability();
        $usableIrr = $payments->walletBalance($user) + $payments->restrictedAdCreditBalance($user);
        $walletBalanceToman = intdiv($usableIrr, 10);
        $walletBalanceUsd = $pricing->irrToUsd($usableIrr);
        $telegramSubmission = $order->currentRevision?->telegramSubmissions->sortByDesc('id')->first();
        $latestEvent = $order->statusEvents->sortByDesc('id')->first();

        return view('app.campaigns.show', compact(
            'order', 'user', 'fundingCards', 'canUseRialPayments', 'walletBalanceToman',
            'walletBalanceUsd', 'telegramSubmission', 'latestEvent', 'zarinPayEnabled',
            'nowPaymentsEnabled',
        ));
    }

    public function edit(Request $request, Order $campaign): View
    {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        abort_unless($order->status === OrderStatus::ChangesRequested, 422, 'فقط سفارش نیازمند اصلاح قابل ویرایش است.');
        $order->load('currentRevision.targets');
        $categories = TargetCategory::query()->where('is_active', true)
            ->with(['channels' => fn ($query) => $query->where('is_active', true)
                ->orderByRaw("CASE WHEN language = 'fa' THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN is_featured = 1 THEN 0 ELSE 1 END")
                ->orderByDesc('members_count')
                ->limit(30)])
            ->orderBy('sort_order')->get();
        $suggestedChannels = \App\Models\SuggestedChannel::query()->where('is_active', true)
            ->orderByRaw("CASE WHEN language = 'fa' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN is_featured = 1 THEN 0 ELSE 1 END")
            ->orderByDesc('members_count')
            ->limit(60)->get();
        $draft = $order;
        $editing = true;
        $quote = [
            'media_budget_toman' => intdiv($order->media_budget_irr, 10),
            'service_markup_percent' => $order->service_markup_bps / 100,
            'service_fee_toman' => intdiv($order->service_fee_irr, 10),
            'gateway_fee_toman' => intdiv($order->gateway_fee_irr, 10),
            'total_toman' => intdiv($order->total_irr, 10),
            'total_usd' => (float) $order->usd_amount,
            // Expose the stored rates (snapshot from quote time) so the JS
            // can still convert gram↔toman in edit mode. We do NOT refresh
            // them in edit mode — the budget is locked.
            'usd_to_irr_rate' => (float) $order->usd_to_irr_rate,
            'gram_to_usd_rate' => (float) $order->gram_to_usd_rate,
            'media_budget_gram' => $order->usd_to_irr_rate > 0 && $order->gram_to_usd_rate > 0
                ? ($order->media_budget_irr) / ($order->usd_to_irr_rate * $order->gram_to_usd_rate)
                : 0,
        ];
        // In edit mode the budget is locked, but we still expose the min
        // so the inline validator on the budget field can run without
        // throwing on the missing variable.
        $minimumOrderToman = intdiv((int) config('ads-platform.minimum_order_irr', 1_000_000), 10);
        [$zarinPayEnabled, $nowPaymentsEnabled] = $this->paymentAvailability();

        return view('app.campaigns.create', compact(
            'categories', 'draft', 'editing', 'quote', 'order', 'zarinPayEnabled',
            'nowPaymentsEnabled', 'suggestedChannels', 'minimumOrderToman',
        ));
    }

    public function update(
        Request $request,
        Order $campaign,
        CampaignContentValidator $contentValidator,
        CampaignTransitionService $transitions,
    ): RedirectResponse {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        abort_unless($order->status === OrderStatus::ChangesRequested, 422);

        $data = $request->validate([
            'internal_title' => ['required', 'string', 'max:120'],
            'ad_text' => ['required', 'string', 'max:160', 'not_regex:/\R/u'],
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'placement_type' => ['required', Rule::in(['channel_posts', 'search_results', 'bot_messages'])],
            'impression_goal' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'frequency_cap' => ['nullable', 'integer', 'min:1', 'max:10'],
            'daily_view_limit_per_user' => ['required', 'integer', 'min:1', 'max:4'],
            'plan' => ['required', Rule::in(['standard', 'competitive'])],
            'cpm_gram' => ['required', 'numeric', 'min:0.1', 'max:1000000'],
            'language' => ['nullable', Rule::in(['fa', 'en'])],
            'media_budget_toman' => ['required', 'integer'],
            'target_channel_ids' => ['nullable', 'array', 'max:100'],
            'target_channel_ids.*' => ['integer', 'exists:suggested_channels,id'],
            'manual_channels' => ['nullable', 'string', 'max:5000'],
            'search_keywords' => ['nullable', 'array', 'max:30'],
            'search_keywords.*' => ['string', 'min:4', 'max:64'],
            'terms_accepted' => ['accepted'],
        ]);

        // Derive destination_type for backward compatibility with existing rows.
        $destinationTypeMap = [
            'channel_posts' => 'channel',
            'bot_messages' => 'bot',
            'search_results' => 'channel',
        ];
        $data['destination_type'] = $destinationTypeMap[$data['placement_type']] ?? 'channel';

        if ((int) $data['media_budget_toman'] * 10 !== $order->media_budget_irr) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'media_budget_toman' => 'بودجه سفارش پرداخت‌شده در مرحله اصلاح قابل تغییر نیست.',
            ]);
        }
        // ── Field-level content validation (mirror of store()) ───────────
        // Attach each error to its own field key so the wizard renders the
        // message next to the offending input instead of a top-level flash.
        $adTextErrors = $contentValidator->adTextErrors($data['ad_text']);
        if ($adTextErrors !== []) {
            return back()->withInput()->withErrors(['ad_text' => implode(' ', $adTextErrors)]);
        }
        $destinationErrors = $contentValidator->destinationUrlErrors($data['destination_url']);
        if ($destinationErrors !== []) {
            return back()->withInput()->withErrors(['destination_url' => implode(' ', $destinationErrors)]);
        }
        $riskFlags = $contentValidator->riskFlags($data['ad_text'], $data['destination_url']);

        DB::transaction(function () use ($request, $order, $data, $riskFlags, $transitions): void {
            $revision = $order->revisions()->create([
                'revision_no' => ((int) $order->revisions()->max('revision_no')) + 1,
                'internal_title' => trim($data['internal_title']),
                'ad_text' => trim($data['ad_text']),
                'destination_type' => $data['destination_type'],
                'destination_url' => $data['destination_url'],
                'placement_type' => $data['placement_type'],
                'targeting_payload' => ['mode' => $data['placement_type'], 'automated_content_flags' => $riskFlags],
                'impression_goal' => $data['impression_goal'] ?? null,
                'frequency_cap' => $data['frequency_cap'] ?? null,
                'daily_view_limit_per_user' => (int) $data['daily_view_limit_per_user'],
                'plan' => $data['plan'],
                'cpm_gram' => $data['cpm_gram'],
                'language' => $data['language'] ?? $request->user()->locale,
                'search_keywords' => $data['search_keywords'] ?? null,
            ]);
            $this->storeTargets($revision, $data);
            $order->update(['current_revision_id' => $revision->getKey()]);
            $transitions->transition($order, OrderStatus::SupportReview, $request->user(), 'customer_revision_submitted');

            if ($riskFlags !== []) {
                $order->operatorTasks()->create([
                    'type' => 'content_risk_review',
                    'status' => 'open',
                    'context' => ['flags' => $riskFlags, 'source' => 'heuristic_v1', 'revision_id' => $revision->id],
                ]);
            }
        });

        return redirect()->route('app.campaigns.show', $order)->with('success', 'نسخه اصلاح‌شده برای بررسی پشتیبانی ارسال شد.');
    }

    public function requestPause(Request $request, Order $campaign, CampaignTransitionService $service): RedirectResponse
    {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        $service->transition(
            $order,
            OrderStatus::PauseRequested,
            $request->user(),
            'user_requested_pause',
            'درخواست توقف از مینی‌اپ ثبت شد.',
        );

        return back()->with('success', 'درخواست توقف ثبت شد. اعمال آن در حساب Telegram ممکن است آنی نباشد.');
    }

    public function requestResume(Request $request, Order $campaign, CampaignTransitionService $service): RedirectResponse
    {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        $service->transition(
            $order,
            OrderStatus::ResumeRequested,
            $request->user(),
            'user_requested_resume',
            'درخواست ادامه از مینی‌اپ ثبت شد.',
        );

        return back()->with('success', 'درخواست ادامه ثبت شد و پس از اعمال اپراتور وضعیت به‌روزرسانی می‌شود.');
    }

    public function refreshQuote(Request $request, Order $campaign, PricingService $pricing, AuditLogger $audit): RedirectResponse
    {
        $order = $campaign;
        abort_unless($order->user_id === $request->user()->getKey(), 404);
        abort_unless($order->status === OrderStatus::AwaitingPayment && $order->payment_status->value === 'unfunded', 422);
        abort_if($order->paymentIntents()->whereIn('status', ['pending', 'verifying', 'succeeded'])->exists(), 422, 'برای این سفارش یک پرداخت فعال وجود دارد.');

        $before = ['total_irr' => $order->total_irr, 'quote_expires_at' => $order->quote_expires_at?->toIso8601String()];
        $quote = $pricing->quote($order->media_budget_irr);
        $order->update([
            'service_markup_bps' => $quote['service_markup_bps'],
            'service_fee_irr' => $quote['service_fee_irr'],
            'gateway_fee_irr' => $quote['gateway_fee_irr'],
            'total_irr' => $quote['total_irr'],
            'usd_amount' => $quote['usd_amount'],
            'gram_amount' => $quote['gram_amount'],
            'usd_to_irr_rate' => $quote['usd_to_irr_rate'],
            'gram_to_usd_rate' => $quote['gram_to_usd_rate'],
            'conversion_margin_bps' => $quote['conversion_margin_bps'],
            'rate_source' => $quote['rate_source'],
            'quoted_at' => now(),
            'quote_expires_at' => now()->addMinutes($pricing->quoteTtlMinutes()),
        ]);
        $audit->log('order.quote_refreshed', $request->user(), $order, $before, ['total_irr' => $order->total_irr, 'quote_expires_at' => $order->quote_expires_at?->toIso8601String()]);

        return back()->with('success', 'قیمت با نرخ فعلی به‌روزرسانی شد و مهلت پرداخت تازه‌ای دریافت کرد.');
    }

    private function storeTargets(CampaignRevision $revision, array $data): void
    {
        // The target_channel_ids array may contain:
        //   - numeric ids (chosen from the admin-curated catalogue)
        //   - string usernames (resolved via the campaign-creation channel search)
        //   - string telegram chat ids ("-1001234567890") from the search
        //
        // Each id is looked up against the `suggested_channels` table.
        // Anything that isn't a numeric id is treated as a username-based
        // manual target — its snapshot is taken from the local catalogue
        // when a row exists; otherwise we record just the username + URL
        // and the operator will verify it manually before launch.
        $items = collect($data['target_channel_ids'] ?? [])
            ->map(fn ($v) => is_string($v) || is_int($v) ? (string) $v : null)
            ->filter()
            ->unique()
            ->take(100);

        foreach ($items as $value) {
            $isNumericId = preg_match('/^[1-9]\d{0,18}$/', $value);
            $channel = null;
            if ($isNumericId) {
                $channel = \App\Models\SuggestedChannel::query()
                    ->where('is_active', true)
                    ->find((int) $value);
            }
            // Also allow Telegram-chat-id lookup against the same table.
            if (! $channel && str_starts_with($value, '-')) {
                $channel = \App\Models\SuggestedChannel::query()
                    ->where('is_active', true)
                    ->where('telegram_chat_id', $value)
                    ->first();
            }
            // Allow a "@username" / "username" lookup against the table
            // so that channels found via the search are correctly attached
            // even when the user didn't pre-load the id.
            if (! $channel) {
                $username = ltrim((string) preg_replace('~^https?://t\.me/~i', '', $value), '@');
                $username = preg_replace('~/.*$~', '', $username);
                if (preg_match('/^[A-Za-z0-9_]{4,64}$/', $username)) {
                    $channel = \App\Models\SuggestedChannel::query()
                        ->where('is_active', true)
                        ->where('username', $username)
                        ->first();
                }
            }

            if ($channel) {
                $revision->targets()->create([
                    'suggested_channel_id' => $channel->getKey(),
                    'source' => 'catalog',
                    'channel_username' => $channel->username,
                    'channel_title' => $channel->title,
                    'public_url' => $channel->public_url,
                    'members_snapshot' => $channel->members_count,
                    'validation_status' => $channel->eligibility_status,
                ]);
                continue;
            }

            // No matching row in the catalogue → treat as manual.
            $username = ltrim((string) preg_replace('~^https?://t\.me/~i', '', $value), '@');
            $username = preg_replace('~/.*$~', '', $username);
            if (! preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
                // Numeric-only or invalid — skip silently; the operator can't
                // post-verify a private channel from a bare chat id anyway.
                continue;
            }
            $revision->targets()->create([
                'source' => 'manual',
                'channel_username' => $username,
                'public_url' => 'https://t.me/'.$username,
                'validation_status' => 'pending',
            ]);
        }

        // Backwards-compat: the legacy `manual_channels` textarea still
        // works the way it always did (whitespace/commas → usernames).
        $manual = preg_split('/[\s,]+/', (string) ($data['manual_channels'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_unique($manual), 0, 100) as $value) {
            $username = ltrim((string) preg_replace('~^https?://t\.me/~i', '', $value), '@/');
            if (! preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
                continue;
            }
            // Skip if we already added this via target_channel_ids.
            $already = $revision->targets()
                ->where('channel_username', $username)
                ->exists();
            if ($already) {
                continue;
            }
            $revision->targets()->create([
                'source' => 'manual',
                'channel_username' => $username,
                'public_url' => 'https://t.me/'.$username,
                'validation_status' => 'pending',
            ]);
        }
    }

    /** @return array{0: bool, 1: bool} */
    private function paymentAvailability(): array
    {
        return [
            (bool) config('services.zarinpay.enabled')
                || (app()->isLocal() && (bool) config('services.zarinpay.mock')),
            (bool) config('services.nowpayments.enabled'),
        ];
    }

    /**
     * Resolve a Telegram channel/bot by username, link, or numeric chat id.
     *
     * This endpoint powers the campaign-creation "search channel by ID"
     * UX. The user types @username, https://t.me/channel, or a numeric
     * -100... chat id, presses Enter, and we return:
     *   {
     *     "id": "...",
     *     "username": "...",
     *     "title": "...",
     *     "avatar": "..."
     *   }
     *
     * We try our local `suggested_channels` table first (admin-curated
     * catalogue) for instant results + a fallback avatar. If not found
     * there we call Telegram's `getChat` via the bot client. We never
     * reveal private channels (Telegram refuses to resolve them for a
     * bot that is not a member).
     */
    public function searchChannel(Request $request, \App\Services\Telegram\TelegramBotClient $botClient): \Illuminate\Http\JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:128']]);
        $raw = trim((string) $request->input('q'));
        if ($raw === '') {
            return response()->json(['error' => 'empty'], 422);
        }

        // Normalise: accept "@channel", "t.me/channel", "https://t.me/channel",
        // or a numeric "-1001234567890" chat id.
        $isNumericChatId = preg_match('/^-?\d{5,}$/', $raw);
        $username = $raw;
        if (! $isNumericChatId) {
            $username = preg_replace('~^https?://t\.me/~i', '', $raw);
            $username = preg_replace('~^@~', '', $username);
            $username = preg_replace('~/.*$~', '', $username);
            $username = trim($username);
            if (! preg_match('/^[A-Za-z0-9_]{4,64}$/', $username)) {
                return response()->json(['error' => 'invalid'], 422);
            }
        }

        // 1. Try the admin-curated catalogue first.
        $local = \App\Models\SuggestedChannel::query()
            ->where('is_active', true)
            ->when($isNumericChatId, fn ($q) => $q->where('telegram_chat_id', $raw))
            ->when(! $isNumericChatId, fn ($q) => $q->where('username', $username))
            ->first();
        if ($local) {
            return response()->json([
                'id' => $local->telegram_chat_id ?? (string) $local->id,
                'username' => $local->username,
                'title' => $local->title,
                'avatar' => $local->avatar_url,
                'members' => $local->members_count,
                'source' => 'catalog',
            ]);
        }

        // 2. Fall back to Telegram's getChat for public channels / bots.
        $chatId = $isNumericChatId ? $raw : '@'.$username;
        $chat = $botClient->getChat($chatId);
        if (! is_array($chat) || empty($chat['username'])) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $photoUrl = null;
        if (isset($chat['photo']['big_file_id'])) {
            $file = $botClient->getFile($chat['photo']['big_file_id']);
            if ($file !== null && ($file['file_path'] ?? null) !== null) {
                $photoUrl = $botClient->fileDownloadUrl($file['file_path']);
            }
        } elseif (isset($chat['photo']['small_file_id'])) {
            $file = $botClient->getFile($chat['photo']['small_file_id']);
            if ($file !== null && ($file['file_path'] ?? null) !== null) {
                $photoUrl = $botClient->fileDownloadUrl($file['file_path']);
            }
        }

        return response()->json([
            'id' => (string) ($chat['id'] ?? ''),
            'username' => $chat['username'] ?? $username,
            'title' => $chat['title'] ?? $chat['username'] ?? $username,
            'avatar' => $photoUrl,
            'members' => $chat['members_count'] ?? null,
            'source' => 'telegram',
        ]);
    }
}
