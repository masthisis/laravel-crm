<?php

namespace App\Filament\Admin\Resources\CommissionRuleResource\Pages;

use App\Filament\Admin\Resources\CommissionRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommissionRules extends ListRecords
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
