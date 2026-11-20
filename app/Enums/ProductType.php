<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductType: string implements HasLabel
{
    case OneTime = 'one_time';
    case Subscription = 'subscription';

    public function getLabel(): string
    {
        return match ($this) {
            self::OneTime => 'One-time purchase',
            self::Subscription => 'Subscription',
        };
    }
}
