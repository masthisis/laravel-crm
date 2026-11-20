<?php

namespace App\Filament\Admin\Resources\LeadIntegrationResource\Pages;

use App\Filament\Admin\Resources\LeadIntegrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLeadIntegrations extends ListRecords
{
    protected static string $resource = LeadIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
