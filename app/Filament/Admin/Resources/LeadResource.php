<?php

namespace App\Filament\Admin\Resources;

use App\Enums\LeadStatus;
use App\Filament\Admin\Resources\LeadResource\Pages;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\LeadAssignmentService;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Leads';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Contact')
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('email')->email()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->tel()->maxLength(32),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Source & deal')
                    ->schema([
                        Forms\Components\Select::make('source_id')
                            ->relationship('source', 'name')
                            ->searchable()->preload()->required()
                            ->label('Original source'),
                        Forms\Components\Select::make('re_engaged_source_id')
                            ->relationship('reEngagedSource', 'name')
                            ->searchable()->preload()
                            ->label('Re-engagement source')
                            ->helperText('Set when an old lead comes back through another channel.'),
                        Forms\Components\Select::make('sold_source_id')
                            ->relationship('soldSource', 'name')
                            ->searchable()->preload()
                            ->label('Closing (sold) source'),
                        Forms\Components\Select::make('status')
                            ->options(LeadStatus::class)
                            ->default(LeadStatus::New->value),
                        Forms\Components\TextInput::make('estimated_value')->numeric()->prefix(config('crm.currency', 'USD')),
                        Forms\Components\TextInput::make('sold_amount')->numeric()->prefix(config('crm.currency', 'USD')),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Assignment & customer')
                    ->schema([
                        Forms\Components\Select::make('assigned_agent_id')
                            ->relationship('agent', 'name', modifyQueryUsing: fn (Builder $query) => $query->role('sales_staff'))
                            ->searchable()->preload()
                            ->label('Assigned agent'),
                        Forms\Components\Select::make('customer_id')
                            ->relationship('customer', 'name')
                            ->searchable()->preload(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('source.name')
                    ->label('Source')
                    ->badge()
                    ->color(fn (Lead $record) => $record->source->color ?? 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('reEngagedSource.name')
                    ->label('Re-engaged via')
                    ->badge()->color('purple')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->toggleable(),
                Tables\Columns\TextColumn::make('estimated_value')
                    ->money(config('crm.currency', 'USD'))->toggleable(),
                Tables\Columns\TextColumn::make('sold_amount')
                    ->money(config('crm.currency', 'USD'))->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(LeadStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('source')->relationship('source', 'name')->searchable(),
                Tables\Filters\SelectFilter::make('agent')->relationship('agent', 'name')->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('markWon')
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
                                ? 'Lead marked as won — commission '.number_format((float) $commission->amount, 2).' '.config('crm.currency', 'USD')
                                : 'Lead marked as won — no commission rule matched, create one under Commission rules.')
                            ->success()->send();
                    }),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('assign')
                        ->label('Assign to agent')
                        ->icon('heroicon-m-user-plus')
                        ->form([
                            Forms\Components\Select::make('agent_id')
                                ->label('Agent')
                                ->options(fn () => User::role(Roles::SALES_STAFF)->orderBy('name')->pluck('name', 'id'))
                                ->searchable()->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $agent = User::findOrFail($data['agent_id']);
                            app(LeadAssignmentService::class)->assignAll($records, $agent);

                            Notification::make()
                                ->title("Assigned {$records->count()} lead(s) to {$agent->name}.")
                                ->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('distribute')
                        ->label('Distribute evenly')
                        ->icon('heroicon-m-arrows-right-left')
                        ->form([
                            Forms\Components\Select::make('agents')
                                ->label('Agents')
                                ->options(fn () => User::role(Roles::SALES_STAFF)->orderBy('name')->pluck('name', 'id'))
                                ->multiple()->searchable()->required()
                                ->helperText('Leads are spread evenly across the selected agents, balancing their current workload.'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            app(LeadAssignmentService::class)->distribute($records, $data['agents']);

                            Notification::make()
                                ->title("Distributed {$records->count()} lead(s) across ".count($data['agents']).' agent(s).')
                                ->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Lead')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('name'),
                    TextEntry::make('phone')->icon('heroicon-m-phone'),
                    TextEntry::make('email')->icon('heroicon-m-envelope'),
                    TextEntry::make('source.name')->label('Original source')->badge(),
                    TextEntry::make('reEngagedSource.name')->label('Re-engaged via')->badge()->placeholder('—'),
                    TextEntry::make('soldSource.name')->label('Closed via')->badge()->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('estimated_value')->money(config('crm.currency', 'USD'))->label('Estimated value'),
                    TextEntry::make('sold_amount')->money(config('crm.currency', 'USD'))->placeholder('—'),
                ]),
            ]),
            Section::make('Commission preview')
                ->schema([
                    TextEntry::make('commission_estimate')
                        ->label('Estimated commission')
                        ->state(function (Lead $record) {
                            $estimate = app(CommissionService::class)->estimateForLead($record);

                            return $estimate
                                ? number_format($estimate['amount'], 2).' '.config('crm.currency', 'USD')." (rate {$estimate['rate']}% — rule “{$estimate['rule']->name}”)"
                                : 'No matching commission rule';
                        }),
                ]),
            Section::make('Activity log')
                ->schema([
                    RepeatableEntry::make('activities')
                        ->hiddenLabel()
                        ->state(fn (Lead $record) => $record->activities->map(fn ($a) => [
                            'type' => $a->type->label(),
                            'agent' => $a->agent?->name ?? 'system',
                            'notes' => $a->notes,
                            'occurred_at' => $a->occurred_at->format('Y-m-d H:i'),
                        ])->all())
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('agent'),
                            TextEntry::make('notes'),
                            TextEntry::make('occurred_at'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'view' => Pages\ViewLead::route('/{record}'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
