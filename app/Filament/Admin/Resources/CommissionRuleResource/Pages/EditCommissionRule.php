<?php

namespace App\Filament\Admin\Resources\CommissionRuleResource\Pages;

use App\Filament\Admin\Resources\CommissionRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCommissionRule extends EditRecord
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
