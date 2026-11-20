<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssignmentStrategy: string implements HasLabel
{
    case None = 'none';
    case Single = 'single';
    case Distribute = 'distribute';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Unassigned',
            self::Single => 'All to one agent',
            self::Distribute => 'Distributed evenly',
        };
    }
}
