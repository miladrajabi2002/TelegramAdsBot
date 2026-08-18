<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Verifying = 'verifying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Chargeback = 'chargeback';
}
