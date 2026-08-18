<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\PayoutRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $payments = PaymentIntent::query()->with(['user', 'order', 'attempts'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->input('provider')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = trim((string) $request->input('q'));
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->where('public_id', 'like', "%{$term}%")
                        ->orWhere('merchant_reference', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($user) => $user->where('display_name', 'like', "%{$term}%")
                            ->orWhere('telegram_username', 'like', "%{$term}%"))
                        ->orWhereHas('attempts', fn ($attempt) => $attempt->where('provider_reference', 'like', "%{$term}%")
                            ->orWhere('authority', 'like', "%{$term}%"));
                });
            })
            ->latest()->paginate(25, ['*'], 'payments')->withQueryString();
        $ledgerTransactions = LedgerTransaction::with(['entries.account', 'reference'])
            ->latest()->paginate(15, ['*'], 'ledger')->withQueryString();
        $payouts = PayoutRequest::with(['user', 'paymentIntent', 'processor'])
            ->latest()->paginate(15, ['*'], 'payouts')->withQueryString();
        $stats = [
            'verified_deposits_irr' => (int) PaymentIntent::query()
                ->where('purpose', PaymentPurpose::WalletTopUp)
                ->where('status', PaymentStatus::Succeeded)
                ->where('verified_at', '>=', now()->startOfDay())
                ->sum('amount_minor'),
            'held_count' => PaymentIntent::whereIn('status', [PaymentStatus::Verifying, PaymentStatus::ManualReview])->count(),
            'failed_count' => PaymentIntent::where('status', PaymentStatus::Failed)->where('created_at', '>=', now()->subDays(30))->count(),
            'wallet_liability_irr' => LedgerAccount::query()
                ->whereIn('type', ['wallet_available', 'wallet_reserved', 'ad_credit_restricted'])
                ->with('entries:id,ledger_account_id,direction,amount_minor')
                ->get()->sum(fn ($account) => $account->balance()),
        ];
        $transactions = $payments;

        return view('admin.transactions.index', compact('payments', 'transactions', 'ledgerTransactions', 'payouts', 'stats'));
    }

    public function show(PaymentIntent $transaction): View
    {
        $transaction->load(['user', 'order.currentRevision', 'attempts']);
        $ledgerTransactions = LedgerTransaction::query()
            ->with('entries.account')
            ->where('reference_type', $transaction->getMorphClass())
            ->where('reference_id', $transaction->getKey())
            ->latest()->get();

        return view('admin.transactions.show', compact('transaction', 'ledgerTransactions'));
    }
}
