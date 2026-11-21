<?php

use App\Support\Jalali;
use Carbon\Carbon;

/** Jalali calendar conversions (spec 01 §1.2). */
it('converts the documented writing date to Jalali', function () {
    // 2026-11-21 (the day the spec was written) is 1405/08/30.
    [$jy, $jm, $jd] = Jalali::toJalali(2026, 11, 21);

    expect($jy)->toBe(1405)
        ->and($jm)->toBe(8)
        ->and($jd)->toBe(30);

    [$jy, $jm, $jd] = Jalali::toJalali(2026, 11, 22);
    expect([$jy, $jm, $jd])->toBe([1405, 9, 1]);
});

it('round-trips Jalali dates through Gregorian', function () {
    [$gy, $gm, $gd] = Jalali::toGregorian(1405, 8, 30);
    expect([$gy, $gm, $gd])->toBe([2026, 11, 21]);

    [$jy, $jm, $jd] = Jalali::toJalali($gy, $gm, $gd);
    expect([$jy, $jm, $jd])->toBe([1405, 8, 30]);

    [$jy, $jm, $jd] = Jalali::toJalali(2026, 3, 21); // Nowruz
    expect([$jy, $jm, $jd])->toBe([1405, 1, 1]);
});

it('formats and parses Jalali date strings', function () {
    $carbon = Carbon::create(2026, 11, 21, 10, 30, 0, 'Asia/Tehran');

    expect(Jalali::format($carbon))->toBe('1405/08/30')
        ->and(Jalali::format($carbon, 'Y-m-d'))->toBe('1405-08-30')
        ->and(Jalali::formatLong($carbon))->toBe('۳۰ آبان ۱۴۰۵');

    $parsed = Jalali::parse('1405/08/30 10:30');
    expect($parsed?->format('Y-m-d H:i'))->toBe('2026-11-21 10:30');
});

it('builds Jalali month keys and ranges', function () {
    $carbon = Carbon::create(2026, 11, 22, 12, 0, 0, 'Asia/Tehran');

    expect(Jalali::monthKey($carbon))->toBe('1405-09');

    [$start, $end] = Jalali::monthRange('1405-09');
    expect($start->format('Y-m-d'))->toBe('2026-11-22')
        ->and($end->format('Y-m-d'))->toBe('2026-12-22');

    // Azar runs from 22 Nov to 21 Dec 2026 (30 days).
    expect($start->diffInDays($end))->toBe(30.0);
});

it('handles leap-year Esfand correctly', function () {
    // 1403 is a leap Jalali year: Esfand has 30 days.
    [$gy, $gm, $gd] = Jalali::toGregorian(1403, 12, 30);
    expect([$gy, $gm, $gd])->toBe([2025, 3, 20]);

    [$jy, $jm, $jd] = Jalali::toJalali(2025, 3, 20);
    expect([$jy, $jm, $jd])->toBe([1403, 12, 30]);
});
