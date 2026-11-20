<?php

namespace App\Filament\Admin\Resources\LeadSourceResource\Pages;

use App\Filament\Admin\Resources\LeadSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLeadSource extends EditRecord
{
    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
