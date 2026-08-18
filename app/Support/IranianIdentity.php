<?php

namespace App\Support;

class IranianIdentity
{
    public static function digits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    public static function validNationalId(string $value): bool
    {
        $code = preg_replace('/\D/', '', self::digits($value)) ?? '';

        if (strlen($code) !== 10 || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $code[$i]) * (10 - $i);
        }

        $remainder = $sum % 11;
        $check = $remainder < 2 ? $remainder : 11 - $remainder;

        return $check === (int) $code[9];
    }

    public static function validCard(string $value): bool
    {
        $pan = preg_replace('/\D/', '', self::digits($value)) ?? '';
        if (strlen($pan) !== 16 || preg_match('/^(\d)\1{15}$/', $pan)) {
            return false;
        }

        $sum = 0;
        foreach (str_split($pan) as $index => $digit) {
            $coefficient = $index % 2 === 0 ? 2 : 1;
            $product = ((int) $digit) * $coefficient;
            $sum += $product > 9 ? $product - 9 : $product;
        }

        return $sum % 10 === 0;
    }

    /**
     * Heuristic: do two names look like the same person?
     *
     * We compare two Iranian-style full names ("علی رجبی" vs "Ali Rajabi")
     * for similarity. Returns true when:
     *   - The names are equal after lower-casing and trimming.
     *   - OR the transliterated forms of both names are equal.
     *   - OR the first-and-last parts of each match after transliteration
     *     (handles middle-name differences, e.g. "Mohammad Ali Rajabi"
     *     vs "Mohammad Rajobi").
     *
     * Returns false otherwise.
     */
    public static function namesLookSimilar(string $a, string $b): bool
    {
        $a = preg_replace('/\s+/u', ' ', trim($a)) ?? '';
        $b = preg_replace('/\s+/u', ' ', trim($b)) ?? '';
        if ($a === '' || $b === '') {
            return false;
        }
        if (mb_strtolower($a) === mb_strtolower($b)) {
            return true;
        }

        $ta = mb_strtolower(self::transliterate($a));
        $tb = mb_strtolower(self::transliterate($b));
        if ($ta === $tb) {
            return true;
        }
        // Strip non-alpha chars and re-compare.
        $ta = preg_replace('/[^a-z]/', '', $ta) ?? '';
        $tb = preg_replace('/[^a-z]/', '', $tb) ?? '';
        if ($ta !== '' && $ta === $tb) {
            return true;
        }

        // Compare first-name and last-name only (drop middle names).
        $partsA = explode(' ', $ta);
        $partsB = explode(' ', $tb);
        if (count($partsA) >= 2 && count($partsB) >= 2) {
            $firstA = $partsA[0];
            $lastA = $partsA[count($partsA) - 1];
            $firstB = $partsB[0];
            $lastB = $partsB[count($partsB) - 1];
            if ($firstA === $firstB && $lastA === $lastB) {
                return true;
            }
            // Allow first names that share 3+ leading chars (Mohammad / Mohamad).
            $len = min(strlen($firstA), strlen($firstB));
            if ($len >= 3 && str_starts_with($firstA, substr($firstB, 0, $len)) && $lastA === $lastB) {
                return true;
            }
        }

        return false;
    }

    /**
     * A small Persian↔Latin transliteration table. Useful for the
     * common case where the bank statement uses Latin characters but
     * the user typed their name in Persian on the form (or vice-versa).
     */
    public static function transliterate(string $text): string
    {
        static $map = [
            'ا' => 'a', 'آ' => 'a', 'ب' => 'b', 'پ' => 'p', 'ت' => 't',
            'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
            'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh',
            'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't',
            'ظ' => 'z', 'ع' => '',  'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh',
            'ک' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'و' => 'v', 'ه' => 'h', 'ی' => 'y', 'ي' => 'y',
            'ك' => 'k', 'أ' => 'a', 'ؤ' => 'o', 'إ' => 'i', 'ئ' => 'y',
            'ة' => 'h',
        ];
        $out = '';
        foreach (mb_str_split($text) as $char) {
            if (array_key_exists($char, $map)) {
                $out .= $map[$char];
            } else {
                $out .= $char;
            }
        }
        return $out;
    }
}
