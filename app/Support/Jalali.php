<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Jalali (Solar Hijri) calendar conversion — the jdf algorithm, without
 * the day-of-week and locale extras. All legacy CRM UI dates are Jalali
 * while the database stores Gregorian (spec 01 §1.2, glossary "jdf").
 */
final class Jalali
{
    public const MONTH_NAMES = [
        1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /** Gregorian → Jalali [y, m, d]. */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + $gDaysInMonth[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + ($days % 31)];
        }

        return [$jy, 7 + intdiv($days - 186, 30), 1 + (($days - 186) % 30)];
    }

    /** Jalali → Gregorian [y, m, d]. */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;

        $days = -355668
            + (365 * $jy)
            + (intdiv($jy, 33) * 8)
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28;
        $monthDays = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 0;
        while ($gm < 13 && $gd > $monthDays[$gm]) {
            $gd -= $monthDays[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /** Format any date/time as "Y/m/d" (or a custom pattern) in Jalali. */
    public static function format(DateTimeInterface|CarbonInterface|string|null $date, string $pattern = 'Y/m/d'): string
    {
        $carbon = self::carbon($date);
        if ($carbon === null) {
            return '';
        }

        [$jy, $jm, $jd] = self::toJalali($carbon->year, $carbon->month, $carbon->day);

        return strtr($pattern, [
            'Y' => str_pad((string) $jy, 4, '0', STR_PAD_LEFT),
            'm' => str_pad((string) $jm, 2, '0', STR_PAD_LEFT),
            'd' => str_pad((string) $jd, 2, '0', STR_PAD_LEFT),
            'H' => $carbon->format('H'),
            'i' => $carbon->format('i'),
            's' => $carbon->format('s'),
            'M' => self::MONTH_NAMES[$jm],
        ]);
    }

    /** Full Jalali date with month name, e.g. "۱ آذر ۱۴۰۵". */
    public static function formatLong(DateTimeInterface|CarbonInterface|string|null $date): string
    {
        $carbon = self::carbon($date);
        if ($carbon === null) {
            return '';
        }

        [$jy, $jm, $jd] = self::toJalali($carbon->year, $carbon->month, $carbon->day);

        return Persian::toPersian(sprintf('%d %s %d', $jd, self::MONTH_NAMES[$jm], $jy));
    }

    /**
     * Parse a Jalali date "1405/08/30" (optionally "H:i" after a space or
     * "-") into a Carbon instance in the given timezone.
     * Gregorian "2026-11-21" input is also accepted (returned as-is).
     */
    public static function parse(string $value, string $timezone = 'Asia/Tehran'): ?Carbon
    {
        $value = trim(Persian::digits($value) ?? '');
        if ($value === '') {
            return null;
        }

        $time = '00:00:00';
        if (preg_match('/^(\d{4}\/\d{1,2}\/\d{1,2}|\d{4}-\d{1,2}-\d{1,2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            $datePart = $m[1];
            $time = sprintf('%02d:%02d:%02d', (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0));
        } else {
            $datePart = $value;
        }

        if (str_contains($datePart, '-')) {
            // Gregorian input.
            return Carbon::createFromFormat('Y-m-d H:i:s', "$datePart $time", $timezone) ?: null;
        }

        $parts = explode('/', $datePart);
        if (count($parts) !== 3) {
            return null;
        }

        [$jy, $jm, $jd] = array_map('intval', $parts);
        if ($jy < 100) {
            $jy += 1300; // "05/08/30" → 1305? legacy shorthand: treat as 13xx.
        }

        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);

        return Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $gy, $gm, $gd, $time), $timezone) ?: null;
    }

    /** Jalali month key "1405-09" used by commission reporting (09 §2.2). */
    public static function monthKey(DateTimeInterface|CarbonInterface|string|null $date): string
    {
        $carbon = self::carbon($date);
        if ($carbon === null) {
            return '';
        }

        [$jy, $jm] = self::toJalali($carbon->year, $carbon->month, $carbon->day);

        return sprintf('%04d-%02d', $jy, $jm);
    }

    /** Month bounds in Tehran time for a Jalali "YYYY-MM" key. */
    public static function monthRange(string $monthKey, string $timezone = 'Asia/Tehran'): array
    {
        [$jy, $jm] = array_map('intval', explode('-', $monthKey));
        [$gyStart, $gmStart, $gdStart] = self::toGregorian($jy, $jm, 1);
        $start = Carbon::create($gyStart, $gmStart, $gdStart, 0, 0, 0, $timezone);

        $nextMonth = $jm === 12 ? 1 : $jm + 1;
        $nextYear = $jm === 12 ? $jy + 1 : $jy;
        [$gyEnd, $gmEnd, $gdEnd] = self::toGregorian($nextYear, $nextMonth, 1);
        $end = Carbon::create($gyEnd, $gmEnd, $gdEnd, 0, 0, 0, $timezone);

        return [$start, $end];
    }

    private static function carbon(DateTimeInterface|CarbonInterface|string|null $date): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return Carbon::parse($date->format('Y-m-d H:i:s'), $date->getTimezone()->getName());
        }

        return Carbon::parse($date);
    }
}
