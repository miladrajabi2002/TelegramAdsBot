<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function index(Request $request, PricingService $pricing): View
    {
        $user = $request->user();
        $accounts = $user->ledgerAccounts()->with(['entries' => fn ($q) => $q->latest()->limit(30)])->get();
        $payments = $user->paymentIntents()->with('attempts')->latest()->limit(20)->get();
        $payments->each(fn ($payment) => $payment->setAttribute(
            'display_usd',
            $pricing->irrToUsd((int) $payment->amount_minor),
        ));
        $balances = $accounts->mapWithKeys(fn ($account) => [$account->type => $account->balance()]);
        $usableIrr = (int) $balances->get('wallet_available', 0) + (int) $balances->get('ad_credit_restricted', 0);
        $walletBalanceToman = intdiv($usableIrr, 10);
        $walletBalanceUsd = $pricing->irrToUsd($usableIrr);
        $heldBalanceToman = intdiv((int) $balances->get('wallet_reserved', 0), 10);
        $restrictedAdCreditToman = intdiv((int) $balances->get('ad_credit_restricted', 0), 10);
        $fundingCards = $user->fundingCards()->where('status', 'approved')->get();
        $canUseRialPayments = $user->canUseRialPayments();
        $zarinPayEnabled = (bool) config('services.zarinpay.enabled')
            || (app()->isLocal() && (bool) config('services.zarinpay.mock'));
        $nowPaymentsEnabled = (bool) config('services.nowpayments.enabled');
        $transactions = $payments;

        return view('app.wallet.index', compact(
            'user', 'accounts', 'payments', 'transactions', 'balances', 'walletBalanceToman',
            'walletBalanceUsd', 'heldBalanceToman', 'restrictedAdCreditToman',
            'fundingCards', 'canUseRialPayments', 'zarinPayEnabled', 'nowPaymentsEnabled',
        ));
    }
}
