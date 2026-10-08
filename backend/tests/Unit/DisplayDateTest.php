<?php

namespace Tests\Unit;

use App\Support\DisplayDate;
use Tests\TestCase;

class DisplayDateTest extends TestCase
{
    public function test_gregorian_is_the_default_calendar_in_the_configured_timezone(): void
    {
        config(['telegram.reports.calendar' => 'gregorian', 'telegram.reports.timezone' => 'Europe/Berlin']);

        // 12:32 UTC is 14:32 in Berlin (CEST).
        $this->assertSame('2026/09/06 14:32', DisplayDate::format('2026-09-06T12:32:00.000+0000'));
    }

    public function test_jalali_calendar_renders_persian_dates(): void
    {
        config(['telegram.reports.calendar' => 'jalali', 'telegram.reports.timezone' => 'Asia/Tehran']);

        $this->assertSame('1405/06/15 16:02', DisplayDate::format('2026-09-06T12:32:00.000+0000'));
    }

    public function test_an_unset_timezone_falls_back_to_utc(): void
    {
        config(['telegram.reports.calendar' => 'gregorian', 'telegram.reports.timezone' => null]);

        $this->assertSame('2026/09/06 12:32', DisplayDate::format('2026-09-06T12:32:00.000+0000'));
    }

    public function test_missing_or_broken_timestamps_give_null(): void
    {
        $this->assertNull(DisplayDate::format(null));
        $this->assertNull(DisplayDate::format(''));
        $this->assertNull(DisplayDate::format('not a date'));
    }
}
