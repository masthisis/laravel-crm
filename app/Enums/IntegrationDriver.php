<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IntegrationDriver: string implements HasLabel
{
    case Webhook = 'webhook';
    case FormEmbed = 'form_embed';
    case DatabaseSync = 'database_sync';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Webhook => 'Webhook (POST /api/leads)',
            self::FormEmbed => 'Embedded form',
            self::DatabaseSync => 'External DB sync',
            self::Manual => 'Manual',
        };
    }
}
