<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\KycApplication;
use App\Models\LedgerAccount;
use App\Models\OperatorTask;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\PersianDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $from = match (request('period', '30d')) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(6)->startOfDay(),
            'month' => PersianDate::startOfCurrentMonthUtc(),
            default => now()->subDays(29)->startOfDay(),
        };

        $paidOrders = Order::query()->where('payment_status', 'paid')->where('funded_at', '>=', $from);
        $reviewedKyc = KycApplication::query()->whereNotNull('reviewed_at')->where('reviewed_at', '>=', $from)->get(['submitted_at', 'reviewed_at']);
        $telegramDecisions = Order::query()->whereIn('status', [OrderStatus::TelegramApproved, OrderStatus::Scheduled, OrderStatus::Active, OrderStatus::Completed, OrderStatus::TelegramRejected])
            ->where('updated_at', '>=', $from);
        $telegramDecisionCount = (clone $telegramDecisions)->count();
        $approvedDecisionCount = (clone $telegramDecisions)->where('status', '!=', OrderStatus::TelegramRejected)->count();
        $walletLiabilityIrr = LedgerAccount::query()->whereIn('type', ['wallet_available', 'wallet_reserved', 'ad_credit_restricted'])
            ->with('entries:id,ledger_account_id,direction,amount_minor')->get()->sum(fn ($account) => $account->balance());
        $stats = [
            'gross_irr' => (clone $paidOrders)->sum('total_irr'),
            'media_budget_irr' => (clone $paidOrders)->sum('media_budget_irr'),
            'service_revenue_irr' => (clone $paidOrders)->sum('service_fee_irr'),
            'net_revenue_irr' => (clone $paidOrders)->sum('service_fee_irr') - (clone $paidOrders)->sum('gateway_fee_irr'),
            'average_order_irr' => (int) ((clone $paidOrders)->avg('total_irr') ?? 0),
            'wallet_liability_irr' => $walletLiabilityIrr,
            'orders' => Order::where('created_at', '>=', $from)->count(),
            'active_orders' => Order::where('status', OrderStatus::Active)->count(),
            'users' => User::where('created_at', '>=', $from)->count(),
            'failed_payments' => PaymentIntent::where('created_at', '>=', $from)->where('status', PaymentStatus::Failed)->count(),
            'pending_kyc' => KycApplication::whereIn('status', [KycStatus::Submitted, KycStatus::UnderReview])->count(),
            'telegram_approval_rate' => $telegramDecisionCount > 0 ? round($approvedDecisionCount * 100 / $telegramDecisionCount, 1) : null,
            'average_kyc_review_minutes' => $reviewedKyc->isNotEmpty()
                ? (int) round($reviewedKyc->avg(fn ($kyc) => $kyc->submitted_at?->diffInMinutes($kyc->reviewed_at) ?? 0))
                : null,
        ];

        $queues = [
            'kyc' => KycApplication::whereIn('status', [KycStatus::Submitted, KycStatus::UnderReview])->count(),
            'support_review' => Order::where('status', OrderStatus::SupportReview)->count(),
            'telegram_queue' => Order::where('status', OrderStatus::QueuedForTelegram)->count(),
            'pause_requests' => Order::where('status', OrderStatus::PauseRequested)->count(),
            'manual_payments' => PaymentIntent::where('status', PaymentStatus::ManualReview)->count(),
            'operator_tasks' => OperatorTask::where('status', 'open')->count(),
        ];

        $daily = Order::query()->where('payment_status', 'paid')->where('funded_at', '>=', $from)
            ->selectRaw('DATE(funded_at) as day, COUNT(*) as orders_count, COALESCE(SUM(total_irr),0) as gross_irr')
            ->groupBy('day')->orderBy('day')->get();
        $recentOrders = Order::with(['user', 'currentRevision'])->latest()->limit(8)->get();
        $stats += [
            'gross_sales_irr' => $stats['gross_irr'],
            'service_margin_percent' => $stats['gross_irr'] > 0 ? round($stats['service_revenue_irr'] * 100 / $stats['gross_irr'], 1) : 0,
            'active_campaigns' => $stats['active_orders'],
            'active_users' => User::where('last_seen_at', '>=', now()->subDays(30))->count(),
            'pending_orders' => $queues['support_review'] + $queues['telegram_queue'] + $queues['pause_requests'] + $queues['operator_tasks'],
            'held_payments' => $queues['manual_payments'],
            'unanswered_tickets' => SupportTicket::where('status', 'open')->count(),
        ];
        $revenueSeries = $daily->map(fn ($row) => [
            'date' => $row->day,
            'label' => app()->isLocale('fa')
                ? PersianDate::format(Carbon::parse($row->day), 'MM/dd')
                : Carbon::parse($row->day)->format('M j'),
            'gross_irr' => (int) $row->gross_irr,
        ]);

        return view('admin.dashboard', compact('stats', 'queues', 'daily', 'recentOrders', 'revenueSeries', 'from'));
    }
}
