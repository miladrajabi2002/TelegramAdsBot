<?php

namespace App\Enums;

enum TelegramSubmissionStatus: string
{
    case PendingOperator = 'pending_operator';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
}
