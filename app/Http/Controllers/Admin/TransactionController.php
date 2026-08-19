<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use App\Services\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PricingService $pricing,
    ) {
    }

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
        $users = User::query()->orderBy('id', 'desc')->limit(100)->get(['id', 'telegram_user_id', 'display_name', 'telegram_username']);
        $paymentStatuses = collect(PaymentStatus::cases())->map(fn ($s) => $s->value)->all();

        return view('admin.transactions.index', compact(
            'payments', 'transactions', 'ledgerTransactions', 'payouts', 'stats',
            'users', 'paymentStatuses',
        ));
    }

    public function show(PaymentIntent $transaction): View
    {
        $transaction->load(['user', 'order.currentRevision', 'attempts']);
        $ledgerTransactions = LedgerTransaction::query()
            ->with('entries.account')
            ->where('reference_type', $transaction->getMorphClass())
            ->where('reference_id', $transaction->getKey())
            ->latest()->get();

        $paymentStatuses = collect(PaymentStatus::cases())->map(fn ($s) => $s->value)->all();

        return view('admin.transactions.show', compact('transaction', 'ledgerTransactions', 'paymentStatuses'));
    }

    /**
     * Admin-side wallet top-up: creates a Succeeded PaymentIntent and posts
     * a payment_settlement journal entry — effectively the same path the
     * ZarinPay callback takes when a real payment is verified, except here
     * the admin authorised the credit manually (no gateway involved).
     *
     * The intent's provider is set to `admin_adjustment` so audit reports
     * can distinguish admin-initiated credits from real gateway deposits.
     * Both the admin and the affected user get a notification (admin via
     * flash message, user via the existing notifier).
     */
    public function topUpWallet(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount_toman' => ['required', 'integer', 'min:1000', 'max:10000000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = User::findOrFail($data['user_id']);
        $amountToman = (int) $data['amount_toman'];
        $amountIrr = $amountToman * 10;
        $note = trim((string) ($data['note'] ?? ''));

        // Create a settled PaymentIntent with provider=admin_adjustment so
        // the audit trail clearly shows this was NOT a real gateway payment.
        $intent = DB::transaction(function () use ($user, $amountIrr, $amountToman, $note): PaymentIntent {
            $intent = PaymentIntent::create([
                'user_id' => $user->getKey(),
                'purpose' => PaymentPurpose::WalletTopUp,
                'provider' => 'admin_adjustment',
                'merchant_reference' => 'ADMIN-W-'.Str::uuid(),
                'amount_minor' => $amountIrr,
                'currency' => 'IRR',
                // Set to Pending here — settleSuccessfulIntent() will atomically
                // transition it to Succeeded AND post the ledger entries in
                // one DB transaction. Setting it to Succeeded up-front would
                // make settleSuccessfulIntent refuse to re-settle it.
                'status' => PaymentStatus::Pending,
                'expires_at' => now()->addDay(),
                'metadata' => ['admin_topup' => true, 'amount_toman' => $amountToman, 'note' => $note],
            ]);

            // Create a placeholder PaymentAttempt so settleSuccessfulIntent's
            // "find attempt by provider_reference" lookup succeeds. Without
            // this, the settlement would crash with "conflicting provider
            // reference" because there's no attempt row matching the
            // 'admin:<public_id>' provider_reference we pass below.
            $adminRef = 'admin:'.$intent->public_id;
            $intent->attempts()->create([
                'provider_reference' => $adminRef,
                'authority' => $adminRef,
                'redirect_url' => null,
                'verify_code' => '100',
                'provider_response' => ['admin_topup' => true, 'note' => $note],
            ]);

            // Settle: post the journal entries (debit gateway clearing,
            // credit customer wallet_available). We use the same path as a
            // real settlement so the ledger stays consistent.
            $this->payments->settleSuccessfulIntent($intent, $adminRef, [
                'amount_minor' => $amountIrr,
                'currency' => 'IRR',
                'verify_code' => 100,
                'payment_id' => $intent->public_id,
                'merchant_reference' => $intent->merchant_reference,
                'authority' => $adminRef,
                'admin_topup' => true,
                'note' => $note,
            ]);

            return $intent->fresh('attempts');
        });

        $audit->log('payment.admin_topup', $request->user('admin'), $intent, after: [
            'user_id' => $user->getKey(),
            'amount_toman' => $amountToman,
            'note' => $note,
        ]);

        return back()->with('success', sprintf(
            'مبلغ %s تومان به کیف پول کاربر %s (ID: %s) اضافه شد.',
            number_format($amountToman),
            $user->display_name ?: $user->telegram_username,
            $user->getKey(),
        ));
    }

    /**
     * Change a payment intent's status from the admin panel. Used to:
     *   • Manually succeed a held (manual_review) payment after the admin
     *     verifies the gateway receipt out-of-band.
     *   • Mark a stuck verifying payment as failed when the user reports
     *     they never paid.
     *   • Re-open a succeeded payment for manual_review when reversing
     *     a mistaken credit.
     *
     * Status transitions are audited. When transitioning to `succeeded`,
     * we also call settleSuccessfulIntent() so the ledger entries are
     * posted (idempotent — already-succeeded intents are a no-op).
     */
    public function updateStatus(Request $request, PaymentIntent $transaction, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:created,pending,verifying,succeeded,failed,manual_review,expired,cancelled'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = PaymentStatus::from($data['status']);
        $oldStatus = $transaction->status;
        $note = trim((string) ($data['note'] ?? ''));

        // Disallow transitioning an already-succeeded intent to anything
        // other than `succeeded` (would un-credit the wallet). To reverse
        // a mistaken credit, the admin must first manually post a debit
        // via the ledger (not exposed in this UI yet).
        if ($oldStatus === PaymentStatus::Succeeded && $newStatus !== PaymentStatus::Succeeded) {
            throw ValidationException::withMessages([
                'status' => 'برای بازگرداندن یک تراکنش موفق، ابتدا باید موجودی به‌صورت دستی از دفتر کل کسر شود.',
            ]);
        }

        DB::transaction(function () use ($transaction, $newStatus, $oldStatus, $note, $audit): void {
            $before = $transaction->only(['status', 'verified_at']);

            if ($newStatus === PaymentStatus::Succeeded && $oldStatus !== PaymentStatus::Succeeded) {
                // Settle the intent — this posts the ledger entries and
                // marks the intent as succeeded atomically. If the intent
                // was already in manual_review with a known provider
                // reference, we re-use it; otherwise we use the admin id.
                $providerRef = $transaction->attempts()->latest()->value('provider_reference')
                    ?? 'admin:'.$transaction->public_id;
                $this->payments->settleSuccessfulIntent($transaction, $providerRef, [
                    'amount_minor' => (int) $transaction->amount_minor,
                    'currency' => $transaction->currency,
                    'verify_code' => 100,
                    'payment_id' => $transaction->public_id,
                    'merchant_reference' => $transaction->merchant_reference,
                    'authority' => $providerRef,
                    'admin_status_change' => true,
                    'note' => $note,
                ]);
            } else {
                $transaction->forceFill(['status' => $newStatus])->save();
            }

            $audit->log('payment.status_changed', request()->user('admin'), $transaction, before: $before, after: [
                'status' => $newStatus->value,
                'old_status' => $oldStatus->value,
                'note' => $note,
            ]);
        });

        return back()->with('success', sprintf(
            'وضعیت تراکنش %s از «%s» به «%s» تغییر کرد.',
            $transaction->public_id,
            $oldStatus instanceof PaymentStatus ? $oldStatus->value : (string) $oldStatus,
            $newStatus->value,
        ));
    }
}
