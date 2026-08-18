<?php

namespace App\Enums;

enum KycStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case RejectedPermanent = 'rejected_permanent';
    case Revoked = 'revoked';

    public function label(string $locale = 'fa'): string
    {
        $labels = [
            'fa' => [
                self::Draft->value => 'تکمیل‌نشده',
                self::Submitted->value => 'ارسال‌شده',
                self::UnderReview->value => 'در انتظار بررسی',
                self::Approved->value => 'تأییدشده',
                self::ChangesRequested->value => 'نیازمند اصلاح',
                self::RejectedPermanent->value => 'ردشده',
                self::Revoked->value => 'لغوشده',
            ],
            'en' => [
                self::Draft->value => 'Incomplete',
                self::Submitted->value => 'Submitted',
                self::UnderReview->value => 'Under review',
                self::Approved->value => 'Verified',
                self::ChangesRequested->value => 'Needs correction',
                self::RejectedPermanent->value => 'Rejected',
                self::Revoked->value => 'Revoked',
            ],
        ];

        return $labels[$locale][$this->value] ?? $labels['fa'][$this->value];
    }
}
