<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceType: string implements HasLabel
{
    case FormEmbed = 'form_embed';
    case Webhook = 'webhook';
    case Api = 'api';
    case DatabaseSync = 'database_sync';
    case Import = 'import';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::FormEmbed => 'Embedded form',
            self::Webhook => 'Webhook / API',
            self::Api => 'API',
            self::DatabaseSync => 'External DB sync',
            self::Import => 'CSV import',
            self::Manual => 'Manual entry',
        };
    }
}
