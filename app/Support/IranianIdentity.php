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
}
