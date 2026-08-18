<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case SupportReview = 'support_review';
    case ChangesRequested = 'changes_requested';
    case QueuedForTelegram = 'queued_for_telegram';
    case TelegramReview = 'telegram_review';
    case TelegramApproved = 'telegram_approved';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case PauseRequested = 'pause_requested';
    case Paused = 'paused';
    case ResumeRequested = 'resume_requested';
    case TelegramRejected = 'telegram_rejected';
    case Completed = 'completed';
    case CancelledBySupport = 'cancelled_by_support';
    case CancelledByUser = 'cancelled_by_user';
    case ManualAttention = 'manual_attention';

    public function label(string $locale = 'fa'): string
    {
        $fa = [
            self::Draft->value => 'پیش‌نویس',
            self::AwaitingPayment->value => 'در انتظار پرداخت',
            self::SupportReview->value => 'در حال بررسی پشتیبانی',
            self::ChangesRequested->value => 'نیازمند اصلاح',
            self::QueuedForTelegram->value => 'آماده ثبت در تلگرام',
            self::TelegramReview->value => 'ثبت در تلگرام',
            self::TelegramApproved->value => 'تأییدشده توسط تلگرام',
            self::Scheduled->value => 'در انتظار اجرا',
            self::Active->value => 'در حال اجرا',
            self::PauseRequested->value => 'درخواست توقف ثبت شد',
            self::Paused->value => 'متوقف‌شده توسط شما',
            self::ResumeRequested->value => 'درخواست ادامه ثبت شد',
            self::TelegramRejected->value => 'ردشده توسط تلگرام',
            self::Completed->value => 'پایان‌یافته',
            self::CancelledBySupport->value => 'لغوشده توسط پشتیبانی',
            self::CancelledByUser->value => 'لغوشده توسط شما',
            self::ManualAttention->value => 'نیازمند بررسی ویژه',
        ];

        $en = [
            self::Draft->value => 'Draft',
            self::AwaitingPayment->value => 'Awaiting payment',
            self::SupportReview->value => 'Support review',
            self::ChangesRequested->value => 'Needs changes',
            self::QueuedForTelegram->value => 'Queued for Telegram',
            self::TelegramReview->value => 'Submitted to Telegram',
            self::TelegramApproved->value => 'Telegram approved',
            self::Scheduled->value => 'Scheduled',
            self::Active->value => 'Running',
            self::PauseRequested->value => 'Pause requested',
            self::Paused->value => 'Paused by you',
            self::ResumeRequested->value => 'Resume requested',
            self::TelegramRejected->value => 'Rejected by Telegram',
            self::Completed->value => 'Completed',
            self::CancelledBySupport->value => 'Cancelled by support',
            self::CancelledByUser->value => 'Cancelled by you',
            self::ManualAttention->value => 'Manual attention',
        ];

        return ($locale === 'en' ? $en : $fa)[$this->value];
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active, self::Completed, self::TelegramApproved => 'success',
            self::ChangesRequested, self::PauseRequested, self::ResumeRequested, self::ManualAttention => 'warning',
            self::TelegramRejected, self::CancelledBySupport, self::CancelledByUser => 'danger',
            self::TelegramReview, self::QueuedForTelegram, self::Scheduled, self::SupportReview => 'info',
            default => 'neutral',
        };
    }
}
