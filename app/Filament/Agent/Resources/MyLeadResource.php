<?php

namespace App\Filament\Agent\Resources;

use App\Enums\LeadStatus;
use App\Filament\Agent\Resources\MyLeadResource\Pages;
use App\Models\Lead;
use App\Services\CommissionService;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MyLeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('source.name')->label('Source')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('estimated_commission')
                    ->label('Est. commission')
                    ->state(function (Lead $record) {
                        $estimate = app(CommissionService::class)->estimateForLead($record);

                        return $estimate
                            ? number_format($estimate['amount'], 2).' '.config('crm.currency', 'USD').' ('.$estimate['rate'].'%)'
                            : '—';
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(LeadStatus::class)->multiple(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Contact')
                ->description('Everything you need before picking up the phone.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('name'),
                        TextEntry::make('phone')->icon('heroicon-m-phone'),
                        TextEntry::make('email')->icon('heroicon-m-envelope')->placeholder('—'),
                        TextEntry::make('source.name')->label('Original source')->badge(),
                        TextEntry::make('reEngagedSource.name')->label('Re-engaged via')->badge()->color('purple')->placeholder('—'),
                        TextEntry::make('status')->badge(),
                    ]),
                ]),
            Section::make('Commission')
                ->schema([
                    TextEntry::make('commission_estimate')
                        ->label('You would earn')
                        ->state(function (Lead $record) {
                            $estimate = app(CommissionService::class)->estimateForLead($record);

                            return $estimate
                                ? number_format($estimate['amount'], 2).' '.config('crm.currency', 'USD')." (rate {$estimate['rate']}%)"
                                : 'No commission rule matches this lead yet';
                        }),
                    TextEntry::make('estimated_value')->money(config('crm.currency', 'USD'))->label('Estimated deal value')->placeholder('—'),
                ]),
            Section::make('Customer history')
                ->hidden(fn (Lead $record) => $record->customer === null)
                ->schema([
                    TextEntry::make('customer.name')->label('Existing customer'),
                    RepeatableEntry::make('customer.orders')
                        ->label('Purchases')
                        ->state(fn (Lead $record) => $record->customer->orders->map(fn ($o) => [
                            'product' => $o->product?->name ?? '—',
                            'amount' => number_format((float) $o->amount, 2).' '.config('crm.currency', 'USD'),
                            'sold_at' => $o->sold_at?->format('Y-m-d') ?? '—',
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('amount'),
                            TextEntry::make('sold_at'),
                        ])->columns(3),
                    RepeatableEntry::make('customer.subscriptions')
                        ->label('Subscriptions')
                        ->state(fn (Lead $record) => $record->customer->subscriptions->map(fn ($s) => [
                            'product' => $s->product?->name ?? '—',
                            'status' => $s->status->label(),
                            'amount' => number_format((float) $s->amount, 2).' '.config('crm.currency', 'USD').' / '.$s->interval,
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('amount'),
                        ])->columns(3),
                ]),
            Section::make('Activity log')
                ->schema([
                    RepeatableEntry::make('activities')
                        ->hiddenLabel()
                        ->state(fn (Lead $record) => $record->activities->map(fn ($a) => [
                            'type' => $a->type->label(),
                            'notes' => $a->notes,
                            'occurred_at' => $a->occurred_at->format('Y-m-d H:i'),
                        ])->all())
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('notes'),
                            TextEntry::make('occurred_at'),
                        ])->columns(3),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMyLeads::route('/'),
            'view' => Pages\ViewMyLead::route('/{record}'),
        ];
    }
}
