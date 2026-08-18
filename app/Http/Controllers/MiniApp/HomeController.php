<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Services\LedgerService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request, PricingService $pricing, LedgerService $ledger): View
    {
        $user = $request->user();
        $orders = $user->orders()->with('currentRevision')->latest()->limit(5)->get();
        $kyc = $user->kycApplications()->latest('version')->first();

        // Single grouped query instead of 6 sequential SUM queries.
        $balances = $ledger->balancesFor($user);
        $balances = [
            'available_irr' => $balances['wallet_available'] ?? 0,
            'reserved_irr' => $balances['wallet_reserved'] ?? 0,
            'ad_credit_irr' => $balances['ad_credit_restricted'] ?? 0,
        ];

        $usableIrr = $balances['available_irr'] + $balances['ad_credit_irr'];
        $walletBalanceToman = intdiv($usableIrr, 10);
        $walletBalanceUsd = $pricing->irrToUsd($usableIrr);
        $heldBalanceToman = intdiv($balances['reserved_irr'], 10);
        $pendingPayment = $user->paymentIntents()->whereIn('status', ['created', 'pending', 'verifying'])->latest()->first();

        return view('app.home', compact(
            'user', 'orders', 'kyc', 'balances', 'walletBalanceToman',
            'walletBalanceUsd', 'heldBalanceToman', 'pendingPayment',
        ));
    }
}
