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
            ->with(['channels' => fn ($query) => $query->where('is_active', true)->limit(30)])
            ->orderBy('sort_order')->get();
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
        ];
        [$zarinPayEnabled, $nowPaymentsEnabled] = $this->paymentAvailability();

        return view('app.campaigns.create', compact(
            'categories', 'defaults', 'quote', 'zarinPayEnabled', 'nowPaymentsEnabled',
        ));
    }

    public function store(Request $request, CampaignContentValidator $contentValidator, PricingService $pricing): RedirectResponse
    {
        $data = $request->validate([
            'internal_title' => ['required', 'string', 'max:120'],
            'ad_text' => ['required', 'string', 'max:160', 'not_regex:/\R/u'],
            'destination_type' => ['required', Rule::in(['channel', 'bot', 'group', 'website'])],
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'placement_type' => ['required', Rule::in(['channel_posts', 'search_results', 'bot_messages', 'broad'])],
            'impression_goal' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'frequency_cap' => ['nullable', 'integer', 'min:1', 'max:10'],
            'plan' => ['required', Rule::in(['standard', 'competitive'])],
            'language' => ['nullable', Rule::in(['fa', 'en'])],
            'funding_mode' => ['nullable', Rule::in(['wallet', 'zarinpay', 'nowpayments'])],
            'cpm_gram' => ['required', 'numeric', 'min:0.1', 'max:1000000'],
            'media_budget_toman' => ['required', 'integer', 'min:10000', 'max:10000000000'],
            'planned_start_at' => ['nullable', 'date', 'after:now'],
            'target_channel_ids' => ['nullable', 'array', 'max:100'],
            'target_channel_ids.*' => ['integer', 'exists:suggested_channels,id'],
            'manual_channels' => ['nullable', 'string', 'max:5000'],
            'terms_accepted' => ['accepted'],
        ], [
            'ad_text.max' => 'متن تبلیغ حداکثر ۱۶۰ نویسه است.',
            'ad_text.not_regex' => 'شکست خط در متن تبلیغ مجاز نیست.',
            'terms_accepted.accepted' => 'پذیرش قوانین ثبت سفارش الزامی است.',
        ]);

        $warnings = $contentValidator->warnings($data['ad_text'], $data['destination_url']);
        if ($warnings !== []) {
            return back()->withInput()->withErrors(['ad_text' => implode(' ', $warnings)]);
        }
        $riskFlags = $contentValidator->riskFlags($data['ad_text'], $data['destination_url']);

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
                'plan' => $data['plan'],
                'cpm_gram' => $data['cpm_gram'],
                'language' => $data['language'] ?? $request->user()->locale,
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
            ->with(['channels' => fn ($query) => $query->where('is_active', true)->limit(30)])
            ->orderBy('sort_order')->get();
        $draft = $order;
        $editing = true;
        $quote = [
            'media_budget_toman' => intdiv($order->media_budget_irr, 10),
            'service_markup_percent' => $order->service_markup_bps / 100,
            'service_fee_toman' => intdiv($order->service_fee_irr, 10),
            'gateway_fee_toman' => intdiv($order->gateway_fee_irr, 10),
            'total_toman' => intdiv($order->total_irr, 10),
            'total_usd' => (float) $order->usd_amount,
        ];
        [$zarinPayEnabled, $nowPaymentsEnabled] = $this->paymentAvailability();

        return view('app.campaigns.create', compact(
            'categories', 'draft', 'editing', 'quote', 'order', 'zarinPayEnabled',
            'nowPaymentsEnabled',
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
            'destination_type' => ['required', Rule::in(['channel', 'bot', 'group', 'website'])],
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'placement_type' => ['required', Rule::in(['channel_posts', 'search_results', 'bot_messages', 'broad'])],
            'impression_goal' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'frequency_cap' => ['nullable', 'integer', 'min:1', 'max:10'],
            'plan' => ['required', Rule::in(['standard', 'competitive'])],
            'cpm_gram' => ['required', 'numeric', 'min:0.1', 'max:1000000'],
            'language' => ['nullable', Rule::in(['fa', 'en'])],
            'media_budget_toman' => ['required', 'integer'],
            'target_channel_ids' => ['nullable', 'array', 'max:100'],
            'target_channel_ids.*' => ['integer', 'exists:suggested_channels,id'],
            'manual_channels' => ['nullable', 'string', 'max:5000'],
            'terms_accepted' => ['accepted'],
        ]);

        if ((int) $data['media_budget_toman'] * 10 !== $order->media_budget_irr) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'media_budget_toman' => 'بودجه سفارش پرداخت‌شده در مرحله اصلاح قابل تغییر نیست.',
            ]);
        }
        $errors = $contentValidator->warnings($data['ad_text'], $data['destination_url']);
        if ($errors !== []) {
            return back()->withInput()->withErrors(['ad_text' => implode(' ', $errors)]);
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
                'plan' => $data['plan'],
                'cpm_gram' => $data['cpm_gram'],
                'language' => $data['language'] ?? $request->user()->locale,
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
        $suggested = collect($data['target_channel_ids'] ?? [])->unique()->take(100);
        foreach ($suggested as $channelId) {
            $channel = \App\Models\SuggestedChannel::query()->where('is_active', true)->find($channelId);
            if (! $channel) {
                continue;
            }
            $revision->targets()->create([
                'suggested_channel_id' => $channel->getKey(),
                'source' => 'catalog',
                'channel_username' => $channel->username,
                'channel_title' => $channel->title,
                'public_url' => $channel->public_url,
                'members_snapshot' => $channel->members_count,
                'validation_status' => $channel->eligibility_status,
            ]);
        }

        $manual = preg_split('/[\s,]+/', (string) ($data['manual_channels'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_unique($manual), 0, 100) as $value) {
            $username = ltrim((string) preg_replace('~^https?://t\.me/~i', '', $value), '@/');
            if (! preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
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
}
