<?php

namespace App\Filament\Agent\Resources\CustomerResource\Pages;

use App\Filament\Admin\Resources\CustomerResource as AdminCustomerResource;
use App\Filament\Agent\Resources\CustomerResource;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return AdminCustomerResource::customerInfolist($infolist);
    }
}
