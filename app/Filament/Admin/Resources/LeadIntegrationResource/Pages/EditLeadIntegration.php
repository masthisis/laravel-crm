<?php

namespace App\Filament\Admin\Resources\LeadIntegrationResource\Pages;

use App\Filament\Admin\Resources\LeadIntegrationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLeadIntegration extends EditRecord
{
    protected static string $resource = LeadIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
