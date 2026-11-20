<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(32),
                Forms\Components\TextInput::make('company')->maxLength(255),
                Forms\Components\TextInput::make('country')->maxLength(255),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('company')->toggleable(),
                Tables\Columns\TextColumn::make('orders_count')->counts('orders')->label('Purchases'),
                Tables\Columns\TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Subscriptions'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Shared customer-360 infolist (also reused by the agent panel).
     */
    public static function customerInfolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Profile')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->icon('heroicon-m-envelope'),
                    TextEntry::make('phone')->icon('heroicon-m-phone'),
                    TextEntry::make('company')->placeholder('—'),
                    TextEntry::make('country')->placeholder('—'),
                    TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
                ]),
            ]),
            Section::make('Purchases')
                ->description('Every product this customer bought and when.')
                ->schema([
                    RepeatableEntry::make('orders')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->orders->map(fn ($o) => [
                            'product' => $o->product->name ?? '—',
                            'amount' => number_format((float) $o->amount, 2).' '.config('crm.currency', 'USD'),
                            'agent' => $o->agent->name ?? '—',
                            'sold_at' => $o->sold_at?->format('Y-m-d') ?? '—',
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('amount'),
                            TextEntry::make('agent'),
                            TextEntry::make('sold_at'),
                        ])
                        ->columns(4),
                ]),
            Section::make('Subscriptions')
                ->schema([
                    RepeatableEntry::make('subscriptions')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->subscriptions->map(fn ($s) => [
                            'product' => $s->product->name ?? '—',
                            'status' => $s->status->label(),
                            'amount' => number_format((float) $s->amount, 2).' '.config('crm.currency', 'USD').' / '.$s->interval,
                            'started_at' => $s->started_at?->format('Y-m-d') ?? '—',
                            'ends_at' => $s->ends_at?->format('Y-m-d') ?? 'open ended',
                        ])->all())
                        ->schema([
                            TextEntry::make('product'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('amount'),
                            TextEntry::make('started_at'),
                            TextEntry::make('ends_at'),
                        ])
                        ->columns(5),
                ]),
            Section::make('Lead history')
                ->schema([
                    RepeatableEntry::make('leads')
                        ->hiddenLabel()
                        ->state(fn (Customer $record) => $record->leads->map(fn ($l) => [
                            'name' => $l->name,
                            'status' => $l->status->label(),
                            'source' => $l->source->name ?? '—',
                            'created_at' => $l->created_at?->format('Y-m-d') ?? '—',
                        ])->all())
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('source'),
                            TextEntry::make('created_at'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
            Section::make('Upcoming renewals')
                ->description('Subscriptions renewing within 30 days — call before they lapse.')
                ->schema([
                    RepeatableEntry::make('renewalAlerts')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            $window = now()->addDays((int) config('crm.subscriptions.renewal_window', 30));

                            return $record->subscriptions()
                                ->whereNotNull('ends_at')
                                ->where('ends_at', '<=', $window)
                                ->orderBy('ends_at')
                                ->get()
                                ->map(fn ($s) => [
                                    'product' => $s->product->name ?? '—',
                                    'renews_at' => $s->getAttribute('ends_at')?->format('Y-m-d').' ('.$s->getAttribute('ends_at')?->diffForHumans().')',
                                    'status' => $s->status->label(),
                                ])->all();
                        })
                        ->schema([
                            TextEntry::make('product')->badge()->color('warning'),
                            TextEntry::make('renews_at'),
                            TextEntry::make('status')->badge(),
                        ])->columns(3)
                        ->placeholder('No subscriptions renewing in the next 30 days.'),
                ])
                ->collapsed(),
            Section::make('Customer activity')
                ->description('Notes and calls across this customer and their leads — newest first.')
                ->schema([
                    RepeatableEntry::make('activityTimeline')
                        ->hiddenLabel()
                        ->state(function (Customer $record) {
                            return $record->leads->flatMap->activities
                                ->merge($record->activities)
                                ->sortByDesc('occurred_at')
                                ->values()
                                ->map(fn ($a) => [
                                    'type' => $a->type->label(),
                                    'notes' => $a->notes,
                                    'occurred_at' => $a->occurred_at->format('Y-m-d H:i'),
                                    'by' => $a->agent->name ?? 'system',
                                ])->all();
                        })
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('notes'),
                            TextEntry::make('occurred_at'),
                            TextEntry::make('by'),
                        ])->columns(4)
                        ->placeholder('No activity recorded yet.'),
                ])
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
