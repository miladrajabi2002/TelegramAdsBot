<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request, PricingService $pricing): View
    {
        $user = $request->user();
        $orders = $user->orders()->with('currentRevision')->latest()->limit(5)->get();
        $kyc = $user->kycApplications()->latest('version')->first();
        $balances = [
            'available_irr' => $this->balance($user, 'wallet_available'),
            'reserved_irr' => $this->balance($user, 'wallet_reserved'),
            'ad_credit_irr' => $this->balance($user, 'ad_credit_restricted'),
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

    private function balance($user, string $type): int
    {
        return (int) ($user->ledgerAccounts()->where('type', $type)->first()?->balance() ?? 0);
    }
}
