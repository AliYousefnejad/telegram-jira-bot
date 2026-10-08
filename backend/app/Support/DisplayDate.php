<?php

namespace App\Support;

use Illuminate\Support\Facades\Date;
use Morilog\Jalali\Jalalian;

/**
 * Dates shown to users: "Y/m/d H:i" in REPORTS_TIMEZONE, on the calendar set by
 * REPORTS_CALENDAR ("gregorian" by default, or "jalali" for the Persian calendar).
 */
class DisplayDate
{
    /** The formatted date, or null when the timestamp is missing or unparsable. */
    public static function format(mixed $iso): ?string
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            $date = Date::parse($iso)->timezone((string) (config('telegram.reports.timezone') ?: 'UTC'));

            return config('telegram.reports.calendar') === 'jalali'
                ? Jalalian::fromCarbon($date)->format('Y/m/d H:i')
                : $date->format('Y/m/d H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
