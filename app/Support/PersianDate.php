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

        return $formatter->format($date->getTimestamp()) ?: $date->toDateTimeString();
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
}
