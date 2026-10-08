<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;

/**
 * Ghana's statutory public holidays with fixed or computable dates (Public Holidays and Commemorative Days Act, as
 * amended 2019 and 2025). Eid al-Fitr and Eid al-Adha follow the moon and are announced each year, so they are not
 * listed; their effect still shows in the shop's own history.
 */
final class GhanaHolidays
{
    private const FIXED = ['01-01', '01-07', '03-06', '05-01', '07-01', '08-04', '09-21', '12-25', '12-26'];

    /** @var array<int, array<string, true>> */
    private static array $cache = [];

    public static function is(CarbonImmutable $day): bool
    {
        return isset(self::forYear($day->year)[$day->toDateString()]);
    }

    /** @return array<string, true> */
    public static function forYear(int $year): array
    {
        if (! isset(self::$cache[$year])) {
            $days = array_map(fn ($md) => "{$year}-{$md}", self::FIXED);
            $easter = self::easter($year);
            $days[] = $easter->subDays(2)->toDateString(); // Good Friday
            $days[] = $easter->addDay()->toDateString(); // Easter Monday
            $days[] = CarbonImmutable::create($year, 12, 1)->modify('first friday of december')->toDateString(); // Farmers' Day
            self::$cache[$year] = array_fill_keys($days, true);
        }

        return self::$cache[$year];
    }

    /** Easter Sunday (Gregorian, anonymous algorithm) without the calendar extension. */
    public static function easter(int $y): CarbonImmutable
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($y, $month, $day);
    }
}
