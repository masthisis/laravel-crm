<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ActivityType: string implements HasLabel
{
    case Call = 'call';
    case Email = 'email';
    case Sms = 'sms';
    case Meeting = 'meeting';
    case Note = 'note';
    case Sale = 'sale';

    public function getLabel(): string
    {
        return match ($this) {
            self::Call => 'Phone call',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::Meeting => 'Meeting',
            self::Note => 'Note',
            self::Sale => 'Sale',
        };
    }
}
