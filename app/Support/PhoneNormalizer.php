<?php

namespace App\Support;

/**
 * Canonical Iranian mobile normalisation — the one rule used everywhere
 * (spec 01 §1.1, LeadService `PhoneNormalizer`).
 *
 * The canonical form of a mobile is exactly 10 digits starting with "9"
 * (e.g. 9121234567). Anything else is invalid (null).
 */
final class PhoneNormalizer
{
    /** Persian ۰-۹ (U+06F0–06F9) and Arabic ٠-٩ (U+0660–0669) digits. */
    private const EASTERN_DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Invisible separators tolerated inside pasted numbers. */
    private const INVISIBLE = [
        "\u{200C}", // ZWNJ
        "\u{200E}", // LRM
        "\u{200F}", // RLM
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
    ];

    /**
     * Normalise a raw phone into the canonical 9xxxxxxxxx form.
     * Returns null when the value cannot be a valid Iranian mobile.
     */
    public static function canonical(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // 1. Eastern digits to Latin.
        $value = strtr(trim($raw), self::EASTERN_DIGITS);

        // 2. Remove whitespace and separator characters.
        $value = preg_replace('/[\s\-().]/u', '', $value) ?? '';
        $value = str_replace(self::INVISIBLE, '', $value);

        // 3. Drop a single leading "+". From here the value must be digits.
        $value = ltrim($value, '+');
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        // 4. Strip international / long-distance prefixes.
        if (strlen($value) === 14 && str_starts_with($value, '0098')) {
            $value = substr($value, 4);
        } elseif (strlen($value) === 12 && str_starts_with($value, '98')) {
            $value = substr($value, 2);
        } elseif (strlen($value) === 11 && str_starts_with($value, '0')) {
            $value = substr($value, 1);
        }

        // 5. Valid iff exactly 10 digits starting with "9".
        if (strlen($value) !== 10 || ! str_starts_with($value, '9')) {
            return null;
        }

        return $value;
    }

    /**
     * Lookup variants for matching legacy columns that store the same
     * number in other notations (spec 01 §1.1 step 6).
     *
     * @return list<string>
     */
    public static function variants(string $canonical): array
    {
        return [
            $canonical,
            '0'.$canonical,
            '98'.$canonical,
            '+98'.$canonical,
            '0098'.$canonical,
        ];
    }

    /**
     * True when the raw value is a valid mobile (canonical() returns non-null).
     */
    public static function isValid(?string $raw): bool
    {
        return self::canonical($raw) !== null;
    }
}
