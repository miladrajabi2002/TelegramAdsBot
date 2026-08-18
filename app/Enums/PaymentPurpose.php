<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case WalletTopUp = 'wallet_top_up';
    case OrderPayment = 'order_payment';
}
