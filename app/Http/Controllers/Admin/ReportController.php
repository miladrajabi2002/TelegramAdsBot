<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\CampaignMetricSnapshot;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Support\PersianDate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to] = $this->dateRange($request);

        $orders = Order::query()->whereBetween('created_at', [$from, $to]);
        $paid = Order::query()->where('payment_status', 'paid')->whereBetween('funded_at', [$from, $to]);
        $metricDeltas = $this->metricDeltas($from, $to);
        $realizedLedger = $this->realizedLedgerTotals($from, $to);
        $summary = [
            'orders' => (clone $orders)->count(),
            'paid_orders' => (clone $paid)->count(),
            'gross_irr' => (int) (clone $paid)->sum('total_irr'),
            'media_budget_irr' => (int) (clone $paid)->sum('media_budget_irr'),
            'service_revenue_irr' => (int) (clone $paid)->sum('service_fee_irr'),
            'gateway_fees_irr' => (int) (clone $paid)->sum('gateway_fee_irr'),
            'impressions' => $metricDeltas['impressions'],
            'joins' => $metricDeltas['joins'],
            'bot_starts' => $metricDeltas['bot_starts'],
            'successful_payments' => PaymentIntent::whereBetween('verified_at', [$from, $to])->where('status', PaymentStatus::Succeeded)->count(),
            'realized_media_cost_irr' => $realizedLedger['telegram_media_settlement'],
            'realized_service_revenue_irr' => $realizedLedger['managed_service_revenue'],
            'realized_gateway_recovery_irr' => $realizedLedger['gateway_fee_recovery'],
        ];
        $summary['net_revenue_irr'] = max(0, $summary['gross_irr'] - $summary['media_budget_irr'] - $summary['gateway_fees_irr']);
        $summary['realized_settlement_irr'] = array_sum($realizedLedger);

        $daily = Order::query()->where('payment_status', 'paid')->whereBetween('funded_at', [$from, $to])
            ->selectRaw('DATE(funded_at) as day, COUNT(*) as order_count, SUM(total_irr) as gross_irr')
            ->groupBy('day')->orderBy('day')->get();
        $statuses = (clone $orders)->selectRaw('status, COUNT(*) as total')->groupBy('status')->orderByDesc('total')->get();

        $stats = [
            'gross_sales_irr' => $summary['gross_irr'],
            'media_cost_irr' => $summary['media_budget_irr'],
            'service_revenue_irr' => $summary['service_revenue_irr'],
            'net_profit_irr' => $summary['net_revenue_irr'],
            'realized_settlement_irr' => $summary['realized_settlement_irr'],
            'realized_service_revenue_irr' => $summary['realized_service_revenue_irr'],
            'delivered_impressions' => $summary['impressions'],
            ...$summary,
        ];
        $revenueSeries = $daily->map(fn ($row) => [
            'date' => $row->day,
            'label' => app()->isLocale('fa')
                ? PersianDate::format(Carbon::parse($row->day), 'MM/dd')
                : Carbon::parse($row->day)->format('M j'),
            'gross_irr' => (int) $row->gross_irr,
            'orders' => (int) $row->order_count,
        ]);
        $statusBreakdown = $statuses->map(fn ($row) => [
            'status' => $row->status,
            'count' => (int) $row->total,
        ]);
        $paymentBreakdown = PaymentIntent::query()
            ->where('status', PaymentStatus::Succeeded)
            ->whereBetween('verified_at', [$from, $to])
            ->selectRaw('provider, COUNT(*) as count, SUM(amount_minor) as amount_irr')
            ->groupBy('provider')->orderByDesc('amount_irr')->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'count' => (int) $row->count,
                'amount_irr' => (int) $row->amount_irr,
            ]);

        $walletOrders = Order::query()->where('payment_status', 'paid')
            ->where('funding_mode', 'wallet')->whereBetween('funded_at', [$from, $to]);
        if ((clone $walletOrders)->exists()) {
            $paymentBreakdown->push([
                'provider' => 'wallet',
                'count' => (clone $walletOrders)->count(),
                'amount_irr' => (int) (clone $walletOrders)->sum('total_irr'),
            ]);
        }

        $topCategories = DB::table('target_categories')
            ->join('target_category_channels', 'target_category_channels.target_category_id', '=', 'target_categories.id')
            ->join('campaign_targets', 'campaign_targets.suggested_channel_id', '=', 'target_category_channels.suggested_channel_id')
            ->join('orders', 'orders.current_revision_id', '=', 'campaign_targets.campaign_revision_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('target_categories.title_fa, target_categories.title_en, COUNT(DISTINCT orders.id) as aggregate_value')
            ->groupBy('target_categories.id', 'target_categories.title_fa', 'target_categories.title_en')
            ->orderByDesc('aggregate_value')->limit(8)->get()
            ->map(fn ($row) => [
                'label' => app()->isLocale('fa') ? $row->title_fa : $row->title_en,
                'value' => (int) $row->aggregate_value,
            ]);

        return view('admin.reports.index', compact(
            'from', 'to', 'summary', 'daily', 'statuses', 'stats', 'revenueSeries',
            'statusBreakdown', 'paymentBreakdown', 'topCategories',
        ));
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $orders = Order::query()->with(['user', 'currentRevision'])
            ->whereBetween('created_at', [$from, $to])->oldest()->cursor();

        return response()->streamDownload(function () use ($orders): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'order_id', 'customer', 'telegram_id', 'status', 'payment_status',
                'funding_mode', 'media_budget_irr', 'service_fee_irr', 'gateway_fee_irr',
                'total_irr', 'created_at', 'funded_at',
            ]);

            foreach ($orders as $order) {
                $safeCustomer = preg_match('/^[=+\-@]/u', (string) $order->user?->display_name)
                    ? "'".$order->user?->display_name
                    : $order->user?->display_name;
                fputcsv($output, [
                    $order->public_id,
                    $safeCustomer,
                    $order->user?->telegram_user_id,
                    $order->status->value,
                    $order->payment_status->value,
                    $order->funding_mode,
                    $order->media_budget_irr,
                    $order->service_fee_irr,
                    $order->gateway_fee_irr,
                    $order->total_irr,
                    $order->created_at?->toIso8601String(),
                    $order->funded_at?->toIso8601String(),
                ]);
            }
            fclose($output);
        }, 'ads-platform-report-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dateRange(Request $request): array
    {
        $request->validate([
            'preset' => ['nullable', 'in:today,7d,30d,month,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $timezone = (string) config('ads-platform.display_timezone', 'Asia/Tehran');
        $to = now($timezone)->endOfDay();
        $from = match ($request->input('preset', '30d')) {
            'today' => now($timezone)->startOfDay(),
            '7d' => now($timezone)->subDays(6)->startOfDay(),
            'month' => Carbon::instance(PersianDate::startOfCurrentMonthUtc())->setTimezone($timezone),
            'custom' => $request->filled('from') ? Carbon::parse($request->input('from'), $timezone)->startOfDay() : now($timezone)->subDays(29)->startOfDay(),
            default => now($timezone)->subDays(29)->startOfDay(),
        };
        if ($request->input('preset') === 'custom' && $request->filled('to')) {
            $to = Carbon::parse($request->input('to'), $timezone)->endOfDay();
        }

        return [$from->utc(), $to->utc()];
    }

    /** @return array{impressions: int, joins: int, bot_starts: int} */
    private function metricDeltas(Carbon $from, Carbon $to): array
    {
        $totals = ['impressions' => 0, 'joins' => 0, 'bot_starts' => 0];
        $snapshotsByOrder = CampaignMetricSnapshot::query()
            ->where('as_of_at', '<=', $to)
            ->orderBy('as_of_at')
            ->get()
            ->groupBy('order_id');

        foreach ($snapshotsByOrder as $snapshots) {
            $end = $snapshots->last();
            $baseline = $snapshots->filter(fn (CampaignMetricSnapshot $snapshot): bool => $snapshot->as_of_at->lt($from))->last();

            foreach (array_keys($totals) as $field) {
                $totals[$field] += max(0, (int) data_get($end, $field, 0) - (int) data_get($baseline, $field, 0));
            }
        }

        return $totals;
    }

    /** @return array{telegram_media_settlement: int, managed_service_revenue: int, gateway_fee_recovery: int} */
    private function realizedLedgerTotals(Carbon $from, Carbon $to): array
    {
        $types = ['telegram_media_settlement', 'managed_service_revenue', 'gateway_fee_recovery'];
        $totals = array_fill_keys($types, 0);
        $rows = LedgerEntry::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->whereIn('ledger_accounts.type', $types)
            ->whereBetween('ledger_entries.created_at', [$from, $to])
            ->selectRaw("ledger_accounts.type, SUM(CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END) AS net_amount")
            ->groupBy('ledger_accounts.type')
            ->get();

        foreach ($rows as $row) {
            $totals[$row->type] = (int) $row->net_amount;
        }

        return $totals;
    }
}
