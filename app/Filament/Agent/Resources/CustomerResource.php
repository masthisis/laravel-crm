<?php

namespace App\Filament\Agent\Resources;

use App\Filament\Admin\Resources\CustomerResource as AdminCustomerResource;
use App\Filament\Agent\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only customer lookup for agents — the "customer 360"
 * page they open before making a call.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-up-right';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return AdminCustomerResource::form($form);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('orders_count')->counts('orders')->label('Purchases'),
                Tables\Columns\TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Subscriptions'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentCustomers::route('/'),
            'view' => Pages\ViewAgentCustomer::route('/{record}'),
        ];
    }
}
