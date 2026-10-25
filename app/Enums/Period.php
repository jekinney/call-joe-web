<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum Period: string
{
    case Day = 'day';
    case Month = 'month';

    /** The start of the period containing $at. */
    public function start(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Day => $at->startOfDay(),
            self::Month => $at->startOfMonth(),
        };
    }

    /** When the period containing $at ends (and the count resets). */
    public function end(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Day => $this->start($at)->addDay(),
            self::Month => $this->start($at)->addMonthNoOverflow(),
        };
    }
}
