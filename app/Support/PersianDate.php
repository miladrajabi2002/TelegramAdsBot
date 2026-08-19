<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use IntlCalendar;
use IntlDateFormatter;

class PersianDate
{
    public static function format(?CarbonInterface $date, string $pattern = 'yyyy/MM/dd HH:mm'): string
    {
        if (! $date) {
            return '—';
        }

        if (! class_exists(IntlDateFormatter::class)) {
            return $date->timezone(config('ads-platform.display_timezone'))->format('Y/m/d H:i');
        }

        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            config('ads-platform.display_timezone'),
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        $formatted = $formatter->format($date->getTimestamp()) ?: $date->toDateTimeString();

        // IntlDateFormatter with the fa_IR locale renders digits in Persian
        // form (۰۱۲۳۴۵۶۷۸۹). We want every digit the user sees to be a Latin
        // digit so numbers stay consistent across the app and copy-paste
        // keeps working in other tools. Convert Persian + Arabic digits to
        // Latin here, BEFORE returning the formatted string.
        return self::toLatinDigits($formatted);
    }

    public static function startOfCurrentMonthUtc(): CarbonImmutable
    {
        if (! class_exists(IntlCalendar::class)) {
            return CarbonImmutable::now(config('ads-platform.display_timezone'))->startOfMonth()->utc();
        }

        $timezone = (string) config('ads-platform.display_timezone', 'Asia/Tehran');
        $calendar = IntlCalendar::createInstance($timezone, 'fa_IR@calendar=persian');
        $calendar->setTime((float) (CarbonImmutable::now($timezone)->getTimestampMs()));
        $year = $calendar->get(IntlCalendar::FIELD_YEAR);
        $month = $calendar->get(IntlCalendar::FIELD_MONTH);
        $calendar->clear();
        $calendar->set($year, $month, 1, 0, 0, 0);

        return CarbonImmutable::createFromTimestampMs((int) $calendar->getTime(), $timezone)->utc();
    }

    /**
     * Convert Persian (۰-۹) and Arabic-Indic (٠-٩) digits to Latin (0-9).
     *
     * PersianDate::format() uses IntlDateFormatter with the fa_IR locale,
     * which renders digits in Persian form by default. We want every digit
     * displayed in the UI to be Latin so numbers stay consistent across the
     * app and copy-paste keeps working in other tools.
     */
    public static function toLatinDigits(string $value): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace(
            array_merge($persian, $arabic),
            array_merge($latin, $latin),
            $value,
        );
    }
}
