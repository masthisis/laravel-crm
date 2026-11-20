<?php

namespace App\Support;

/**
 * Central registry of role names used across the CRM.
 */
class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const SALES_STAFF = 'sales_staff';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::ADMIN,
            self::SALES_STAFF,
        ];
    }

    /** @return array<int, string> */
    public static function backOffice(): array
    {
        return [
            self::SUPER_ADMIN,
            self::ADMIN,
        ];
    }

    /** @return array<int, string> */
    public static function everyRole(): array
    {
        return [
            self::SUPER_ADMIN,
            self::ADMIN,
            self::SALES_STAFF,
        ];
    }
}
