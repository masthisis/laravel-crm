<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case PastDue = 'past_due';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Cancelled => 'Cancelled',
            self::PastDue => 'Past due',
            self::Expired => 'Expired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Cancelled => 'danger',
            self::PastDue => 'warning',
            self::Expired => 'gray',
        };
    }
}
