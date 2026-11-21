<?php

namespace App\Support;

/**
 * Persian digit and money helpers. The database stores Latin digits and
 * Gregorian timestamps; the UI shows Persian digits, toman amounts and
 * Jalali dates (spec 01 §1.2).
 */
final class Persian
{
    private const TO_PERSIAN = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];

    private const TO_LATIN = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** "۹۱۲…" → "912…" (also handles Arabic digits). */
    public static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtr($value, self::TO_LATIN);
    }

    /** "912…" → "۹۱۲…" for display. */
    public static function toPersian(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtr($value, self::TO_PERSIAN);
    }

    /** 2500000 → "2,500,000 تومان" (Persian digits, toman suffix). */
    public static function toman(int|float|string|null $amount, bool $persianDigits = true): string
    {
        $digits = number_format((float) ($amount ?? 0));
        $label = $digits.' تومان';

        return $persianDigits ? (self::toPersian($digits).' تومان') : $label;
    }

    /** Extract the integer amount from a free-text total ("2500000", "۲,۵۰۰,۰۰۰"). */
    public static function toInt(?string $value): int
    {
        $latin = preg_replace('/[^0-9]/', '', self::digits($value ?? '') ?? '');

        return (int) $latin;
    }
}
