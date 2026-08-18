<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\Order;
use App\Services\CampaignTransitionService;
use App\Services\PaymentService;
use App\Services\Payments\Exceptions\PaymentException;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $orders = Order::query()->with(['user', 'currentRevision'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('payment_status'), fn (Builder $q) => $q->where('payment_status', $request->input('payment_status')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('q'));
                $q->where(function (Builder $nested) use ($term): void {
                    $nested->where('public_id', 'like', "%{$term}%")
                        ->orWhereHas('currentRevision', fn ($revision) => $revision->where('internal_title', 'like', "%{$term}%"))
                        ->orWhereHas('user', fn ($user) => $user->where('display_name', 'like', "%{$term}%")->orWhere('telegram_username', 'like', "%{$term}%"));
                });
            })->latest()->paginate(25)->withQueryString();
        $statuses = OrderStatus::cases();

        return view('admin.orders.index', compact('orders', 'statuses'));
    }

    public function show(Order $order): View
    {
        $order->load([
            'user.fundingCards', 'currentRevision.targets', 'currentRevision.telegramSubmissions',
            'metrics' => fn ($q) => $q->orderBy('as_of_at'), 'statusEvents.actor', 'paymentIntents.attempts', 'operatorTasks',
        ]);

        return view('admin.orders.show', compact('order'));
    }

    public function transition(Request $request, Order $order, CampaignTransitionService $service): RedirectResponse
    {
        $data = $request->validate([
            'to_status' => ['required', Rule::enum(OrderStatus::class)],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $target = OrderStatus::from($data['to_status']);
        if (in_array($target, [OrderStatus::ChangesRequested, OrderStatus::CancelledBySupport, OrderStatus::ManualAttention], true)
            && trim((string) ($data['reason_code'] ?? '')) === ''
            && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => 'برای این تصمیم، دلیل یا یادداشت الزامی است.']);
        }

        try {
            $service->transition($order, $target, auth('admin')->user(), $data['reason_code'] ?? null, $data['note'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }
        SendTelegramMessage::dispatch($order->user->telegram_user_id, 'وضعیت سفارش '.htmlspecialchars($order->public_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' به «'.OrderStatus::from($data['to_status'])->label('fa').'» تغییر کرد.');

        return back()->with('success', 'وضعیت سفارش با ثبت کامل تاریخچه تغییر کرد.');
    }

    public function submitTelegram(Request $request, Order $order, CampaignTransitionService $service): RedirectResponse
    {
        $data = $request->validate([
            'external_ad_id' => ['required', 'string', 'max:150'],
            'external_account_label' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $service->recordTelegramSubmission($order, auth('admin')->user(), $data['external_ad_id'], $data['external_account_label'] ?? null, null, $data['note'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['external_ad_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'شناسه ثبت Telegram ذخیره شد و سفارش وارد مرحله بررسی تلگرام شد.');
    }

    public function telegramDecision(Request $request, Order $order, CampaignTransitionService $service): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
        ]);
        try {
            $service->recordTelegramDecision($order, auth('admin')->user(), $data['decision'] === 'approved', $data['rejection_reason'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['decision' => $exception->getMessage()]);
        }
        SendTelegramMessage::dispatch($order->user->telegram_user_id, $data['decision'] === 'approved'
            ? 'آگهی شما توسط تلگرام تأیید شد.'
            : 'آگهی شما توسط تلگرام رد شد. جزئیات و وضعیت تطبیق مالی را در مینی‌اپ ببینید.');

        return back()->with('success', $data['decision'] === 'approved' ? 'تأیید تلگرام ثبت شد.' : 'رد تلگرام ثبت شد؛ تطبیق مالی باید تکمیل شود.');
    }

    public function telegram(Request $request, Order $order, CampaignTransitionService $service): RedirectResponse
    {
        $data = $request->validate([
            'telegram_action' => ['required', Rule::in(['submitted', 'approved', 'rejected'])],
            'external_ad_id' => ['nullable', 'required_if:telegram_action,submitted', 'string', 'max:150'],
            'external_account_label' => ['nullable', 'string', 'max:150'],
            'rejection_reason' => ['nullable', 'required_if:telegram_action,rejected', 'string', 'max:2000'],
        ]);

        if ($data['telegram_action'] === 'submitted') {
            try {
                $service->recordTelegramSubmission(
                    $order,
                    auth('admin')->user(),
                    $data['external_ad_id'],
                    $data['external_account_label'] ?? null,
                    note: $data['rejection_reason'] ?? null,
                );
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['telegram_action' => $exception->getMessage()]);
            }

            return back()->with('success', 'ثبت آگهی در Telegram ذخیره و سفارش وارد مرحله بررسی شد.');
        }

        $approved = $data['telegram_action'] === 'approved';
        try {
            $service->recordTelegramDecision(
                $order,
                auth('admin')->user(),
                $approved,
                $approved ? null : $data['rejection_reason'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['telegram_action' => $exception->getMessage()]);
        }
        SendTelegramMessage::dispatch(
            $order->user->telegram_user_id,
            $approved
                ? 'آگهی شما توسط تلگرام تأیید شد.'
                : 'آگهی شما توسط تلگرام رد شد. جزئیات و وضعیت تطبیق مالی را در مینی‌اپ ببینید.',
        );

        return back()->with('success', $approved
            ? 'تأیید Telegram ثبت شد.'
            : 'رد Telegram ثبت شد و کار تطبیق مالی در صف اپراتور قرار گرفت.');
    }

    public function reconcileRejection(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'telegram_spent_toman' => ['required', 'integer', 'min:0', 'max:'.intdiv((int) $order->media_budget_irr, 10)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $spentIrr = (int) $data['telegram_spent_toman'] * 10;
        try {
            $payments->reconcileTelegramRejection($order, auth('admin')->user(), $spentIrr, $data['note'] ?? null);
        } catch (PaymentException $exception) {
            throw ValidationException::withMessages(['telegram_spent_toman' => $exception->getMessage()]);
        }
        $creditToman = intdiv((int) $order->media_budget_irr - $spentIrr, 10);
        SendTelegramMessage::dispatch(
            $order->user->telegram_user_id,
            'تطبیق مالی سفارش '.$order->public_id.' انجام شد. اعتبار تبلیغاتی غیرقابل‌برداشت: '.number_format($creditToman).' تومان.',
        );

        return back()->with('success', 'تطبیق مالی ثبت و اعتبار واجد شرایط به حساب تبلیغاتی منتقل شد.');
    }

    public function reconcileCompletion(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'telegram_spent_toman' => ['required', 'integer', 'min:0', 'max:'.intdiv((int) $order->media_budget_irr, 10)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $spentIrr = (int) $data['telegram_spent_toman'] * 10;

        try {
            $payments->reconcileCompletedCampaign($order, auth('admin')->user(), $spentIrr, $data['note'] ?? null);
        } catch (PaymentException $exception) {
            throw ValidationException::withMessages(['telegram_spent_toman' => $exception->getMessage()]);
        }

        $creditToman = intdiv((int) $order->media_budget_irr - $spentIrr, 10);
        SendTelegramMessage::dispatch(
            $order->user->telegram_user_id,
            'تسویه نهایی سفارش '.$order->public_id.' انجام شد. اعتبار تبلیغاتی مصرف‌نشده: '.number_format($creditToman).' تومان.',
        );

        return back()->with('success', 'تسویه نهایی کمپین در دفتر کل ثبت شد.');
    }

    public function storeMetric(Request $request, Order $order): RedirectResponse
    {
        $latest = $order->latestMetrics();
        $data = $request->validate([
            'as_of_at' => ['required', 'date', 'before_or_equal:now', ...($latest ? ['after:'.$latest->as_of_at->toIso8601String()] : [])],
            'impressions' => ['required', 'integer', 'min:'.($latest?->impressions ?? 0)],
            'joins' => ['nullable', 'integer', 'min:0'],
            'bot_starts' => ['nullable', 'integer', 'min:0'],
            'spend_gram' => ['required', 'numeric', 'min:'.($latest?->spend_gram ?? 0)],
            'remaining_budget_gram' => ['nullable', 'numeric', 'min:0'],
        ]);
        $order->metrics()->create([
            ...$data,
            'joins' => $data['joins'] ?? 0,
            'bot_starts' => $data['bot_starts'] ?? 0,
            'source' => 'manual',
            'recorded_by' => auth('admin')->id(),
        ]);

        return back()->with('success', 'Snapshot آمار ذخیره شد؛ گزارش قبلی حذف یا بازنویسی نشد.');
    }
}
