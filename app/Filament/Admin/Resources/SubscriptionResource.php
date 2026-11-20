<?php

namespace App\Filament\Admin\Resources;

use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\SubscriptionResource\Pages;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('order_id')->relationship('order', 'id')->searchable()->preload(),
                Forms\Components\Select::make('status')->options(SubscriptionStatus::class)->default(SubscriptionStatus::Active->value)->required(),
                Forms\Components\TextInput::make('amount')->numeric()->required()->prefix(config('crm.currency', 'USD')),
                Forms\Components\Select::make('interval')->options(['monthly' => 'Monthly', 'yearly' => 'Yearly'])->default('monthly')->required(),
                Forms\Components\DateTimePicker::make('started_at')->default(now())->required(),
                Forms\Components\DateTimePicker::make('ends_at'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->searchable(),
                Tables\Columns\TextColumn::make('product.name'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('amount')->money(config('crm.currency', 'USD')),
                Tables\Columns\TextColumn::make('interval')->badge(),
                Tables\Columns\TextColumn::make('started_at')->date(),
                Tables\Columns\TextColumn::make('ends_at')->date()->placeholder('open'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
