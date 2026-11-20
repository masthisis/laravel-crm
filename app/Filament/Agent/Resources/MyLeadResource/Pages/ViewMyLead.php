<?php

namespace App\Filament\Agent\Resources\MyLeadResource\Pages;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Filament\Agent\Resources\MyLeadResource;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\CommissionService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewMyLead extends ViewRecord
{
    protected static string $resource = MyLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logActivity')
                ->label('Log activity')
                ->icon('heroicon-m-clipboard-document-check')
                ->form([
                    Forms\Components\Select::make('type')->options(ActivityType::class)->required(),
                    Forms\Components\Textarea::make('notes')->required(),
                    Forms\Components\DateTimePicker::make('occurred_at')->default(now())->required(),
                ])
                ->action(function (array $data, Lead $record) {
                    $record->activities()->create([
                        'agent_id' => auth()->id(),
                        'type' => $data['type'],
                        'notes' => $data['notes'],
                        'occurred_at' => $data['occurred_at'],
                    ]);

                    if (blank($record->first_contacted_at) && in_array($data['type'], ['call', 'email', 'sms'], true)) {
                        $record->forceFill([
                            'first_contacted_at' => $data['occurred_at'],
                            'status' => LeadStatus::Contacted,
                        ])->save();
                    }

                    Notification::make()->title('Activity logged.')->success()->send();
                }),

            Action::make('markWon')
                ->label('Mark won')
                ->icon('heroicon-m-trophy')
                ->color('success')
                ->visible(fn (Lead $record) => $record->status !== LeadStatus::Won)
                ->form([
                    Forms\Components\Select::make('sold_source_id')
                        ->label('Closing source')
                        ->options(LeadSource::orderBy('name')->pluck('name', 'id'))
                        ->default(fn (Lead $record) => $record->sold_source_id ?? $record->source_id)
                        ->required(),
                    Forms\Components\TextInput::make('sold_amount')
                        ->numeric()->required()
                        ->default(fn (Lead $record) => $record->estimated_value)
                        ->prefix(config('crm.currency', 'USD')),
                ])
                ->action(function (Lead $record, array $data) {
                    $commission = app(CommissionService::class)->recordSale($record, (float) $data['sold_amount'], (int) $data['sold_source_id']);

                    Notification::make()
                        ->title($commission
                            ? 'Deal won! Your commission: '.number_format((float) $commission->amount, 2).' '.config('crm.currency', 'USD')
                            : 'Deal won! (No commission rule matched — an admin can add one.)')
                        ->success()->send();
                }),
        ];
    }
}
