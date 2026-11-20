<?php

namespace App\Filament\Agent\Resources\CustomerResource\Pages;

use App\Enums\ActivityType;
use App\Filament\Admin\Resources\CustomerResource as AdminCustomerResource;
use App\Filament\Agent\Resources\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return AdminCustomerResource::customerInfolist($infolist);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logCustomerNote')
                ->label('Log customer note')
                ->icon('heroicon-m-clipboard-document-check')
                ->form([
                    Forms\Components\Select::make('type')->options(ActivityType::class)->default(ActivityType::Note->value)->required(),
                    Forms\Components\Textarea::make('notes')->required(),
                ])
                ->action(function (array $data, Customer $record) {
                    $record->activities()->create([
                        'agent_id' => auth()->id(),
                        'type' => $data['type'],
                        'notes' => $data['notes'],
                        'occurred_at' => now(),
                    ]);

                    Notification::make()->title('Customer note logged.')->success()->send();
                }),
        ];
    }
}
