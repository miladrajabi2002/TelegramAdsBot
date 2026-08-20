<?php

namespace App\Services;

class CampaignContentValidator
{
    /**
     * Field-specific errors for the ad text (`ad_text` form field).
     *
     * These checks mirror the JS-side validator in resources/js/app.js
     * (setupInlineValidator for #ad-text). They MUST stay in sync so the
     * user gets the same message in the browser and on the server.
     *
     * @return array<int, string>
     */
    public function adTextErrors(string $text): array
    {
        $errors = [];

        if (preg_match('/\R/u', $text)) {
            $errors[] = 'متن آگهی نباید شکست خط داشته باشد.';
        }

        preg_match_all('~(?:https?://|t\.me/|@[a-zA-Z0-9_]{5,})~iu', $text, $matches);
        if (count($matches[0]) > 1) {
            $errors[] = 'در متن آگهی بیش از یک لینک شناسایی شد.';
        }

        return $errors;
    }

    /**
     * Field-specific errors for the destination URL (`destination_url`).
     *
     * Mirrors the JS-side validator for #destination-url.
     *
     * @return array<int, string>
     */
    public function destinationUrlErrors(string $destination): array
    {
        $errors = [];

        if (preg_match('~https?://(?:bit\.ly|tinyurl\.com|t\.co|goo\.gl)/~i', $destination)) {
            $errors[] = 'لینک کوتاه‌شده برای مقصد مجاز نیست.';
        }

        if (preg_match('~^https?://(?:\d{1,3}\.){3}\d{1,3}~', $destination)) {
            $errors[] = 'استفاده از آدرس IP به‌عنوان مقصد مجاز نیست.';
        }

        return $errors;
    }

    /**
     * Legacy combined warnings list — kept for backward compat with any
     * caller that still wants a single flat list. New code should call
     * adTextErrors() / destinationUrlErrors() so the resulting validation
     * error is attached to the correct field key.
     *
     * @return array<int, string>
     */
    public function warnings(string $text, string $destination): array
    {
        return array_merge(
            $this->adTextErrors($text),
            $this->destinationUrlErrors($destination),
        );
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
