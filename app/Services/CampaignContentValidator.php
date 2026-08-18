<?php

namespace App\Services;

class CampaignContentValidator
{
    /** @return array<int, string> */
    public function warnings(string $text, string $destination): array
    {
        $warnings = [];

        if (preg_match('/\R/u', $text)) {
            $warnings[] = 'متن آگهی نباید شکست خط داشته باشد.';
        }

        preg_match_all('~(?:https?://|t\.me/|@[a-zA-Z0-9_]{5,})~iu', $text, $matches);
        if (count($matches[0]) > 1) {
            $warnings[] = 'در متن آگهی بیش از یک لینک شناسایی شد.';
        }

        if (preg_match('~https?://(?:bit\.ly|tinyurl\.com|t\.co|goo\.gl)/~i', $destination)) {
            $warnings[] = 'لینک کوتاه‌شده برای مقصد مجاز نیست.';
        }

        if (preg_match('~^https?://(?:\d{1,3}\.){3}\d{1,3}~', $destination)) {
            $warnings[] = 'استفاده از آدرس IP به‌عنوان مقصد مجاز نیست.';
        }

        return $warnings;
    }

    /** @return array<int, string> */
    public function riskFlags(string $text, string $destination): array
    {
        $haystack = mb_strtolower($text.' '.$destination);
        $patterns = [
            'adult_or_sexual' => '/(?:پورن|سکس|برهنه|porn|adult\s*content|dating)/iu',
            'gambling' => '/(?:شرط\s*بندی|قمار|کازینو|لاتاری|betting|casino|lottery)/iu',
            'drugs_or_alcohol' => '/(?:مواد\s*مخدر|مشروب|سیگار|تنباکو|narcotic|alcohol|tobacco)/iu',
            'weapons' => '/(?:اسلحه|تفنگ|مهمات|مواد\s*منفجره|weapon|firearm|explosive)/iu',
            'politics_or_religion' => '/(?:انتخابات|حزب\s*سیاسی|کاندیدا|تبلیغ\s*دینی|election|political\s*party|religious\s*campaign)/iu',
            'harmful_finance' => '/(?:سود\s*تضمینی|ثروتمند\s*شدن\s*سریع|طرح\s*هرمی|guaranteed\s*return|get\s*rich\s*quick|pyramid\s*scheme)/iu',
            'malware_or_manipulation' => '/(?:فیشینگ|هک|افزایش\s*فیک|ممبر\s*فیک|phishing|malware|fake\s*followers|auto\s*click)/iu',
            'medical_claim' => '/(?:درمان\s*قطعی|کاهش\s*وزن\s*تضمینی|داروی\s*بدون\s*مجوز|miracle\s*cure|guaranteed\s*weight\s*loss)/iu',
        ];

        return collect($patterns)
            ->filter(fn (string $pattern): bool => preg_match($pattern, $haystack) === 1)
            ->keys()->values()->all();
    }
}
