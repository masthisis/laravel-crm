<?php

namespace App\Filament\Agent\Resources\CustomerResource\Pages;

use App\Filament\Agent\Resources\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;
}
