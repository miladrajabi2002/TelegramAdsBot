<?php

namespace App\Enums;

enum KycReasonCode: string
{
    case UnreadableId = 'unreadable_id';
    case SelfieMismatch = 'selfie_mismatch';
    case CardOwnerMismatch = 'card_owner_mismatch';
    case PhoneMismatch = 'phone_mismatch';
    case DuplicateIdentity = 'duplicate_identity';
    case DocumentExpired = 'document_expired';
    case SuspectedFraud = 'suspected_fraud';
    case MissingDocuments = 'missing_documents';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::UnreadableId => 'تصویر کارت ملی خوانا نیست',
            self::SelfieMismatch => 'تصویر شخص و کارت ملی قابل تطبیق نیست',
            self::CardOwnerMismatch => 'شماره کارت با مشخصات هویتی مطابقت ندارد',
            self::PhoneMismatch => 'شماره همراه قابل تطبیق نیست',
            self::DuplicateIdentity => 'این هویت قبلاً در حساب دیگری ثبت شده است',
            self::DocumentExpired => 'مدرک هویتی معتبر نیست',
            self::SuspectedFraud => 'درخواست نیازمند بررسی بیشتر است',
            self::MissingDocuments => 'مدارک ناقص هستند',
            self::Other => 'سایر موارد',
        };
    }
}
