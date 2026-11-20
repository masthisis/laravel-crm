<?php

namespace App\Filament\Agent\Resources\MyLeadResource\Pages;

use App\Filament\Agent\Resources\MyLeadResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMyLeads extends ListRecords
{
    protected static string $resource = MyLeadResource::class;

    protected function getTableQuery(): Builder
    {
        return MyLeadResource::getModel()::query()
            ->where('assigned_agent_id', auth()->id());
    }
}
