<?php

namespace App\Enums;

enum OrderPaymentStatus: string
{
    case Unfunded = 'unfunded';
    case Pending = 'pending';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Chargeback = 'chargeback';
}
