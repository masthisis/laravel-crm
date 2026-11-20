<?php

namespace App\Filament\Admin\Resources\CommissionRuleResource\Pages;

use App\Filament\Admin\Resources\CommissionRuleResource;
use App\Models\LeadSource;
use App\Services\CommissionService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCommissionRules extends ListRecords
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('simulate')
                ->label('Simulate')
                ->icon('heroicon-m-beaker')
                ->color('gray')
                ->form([
                    Select::make('original_source_id')
                        ->label('Original source')
                        ->options(fn () => LeadSource::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->helperText('Leave empty for a wildcard combination.'),
                    Select::make('re_engaged_source_id')
                        ->label('Re-engagement source')
                        ->options(fn () => LeadSource::orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                    Select::make('sold_source_id')
                        ->label('Closing (sold) source')
                        ->options(fn () => LeadSource::orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                    TextInput::make('amount')
                        ->numeric()->default(1000)->minValue(0)
                        ->prefix(config('crm.currency', 'USD')),
                ])
                ->action(function (array $data) {
                    $result = app(CommissionService::class)->simulate(
                        $data['original_source_id'] !== null ? (int) $data['original_source_id'] : null,
                        $data['re_engaged_source_id'] !== null ? (int) $data['re_engaged_source_id'] : null,
                        $data['sold_source_id'] !== null ? (int) $data['sold_source_id'] : null,
                        (float) ($data['amount'] ?? 1000),
                    );

                    if ($result['winner'] === null) {
                        Notification::make()
                            ->title('No rule matches this combination')
                            ->body('Add a rule for these sources — or a wildcard rule (all sources empty) as the fallback.')
                            ->warning()->send();

                        return;
                    }

                    $ranking = collect($result['candidates'])
                        ->map(fn (array $candidate) => str($candidate['rule']->name)
                            ->append(' — rate '.$candidate['rate'].'%, specificity '.$candidate['specificity'].', priority '.$candidate['priority']))
                        ->join("\n");

                    Notification::make()
                        ->title('Winner: '.$result['winner']->name.' ('.$result['winner']->rate.'%)')
                        ->body(
                            'Commission on this sale: '.number_format($result['amount'], 2).' '.config('crm.currency', 'USD')."\n\n"
                            .'All matching rules, ranked like the engine ranks them:'."\n".$ranking
                        )
                        ->success()->persistent()->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
