<?php

namespace App\Filament\Admin\Resources;

use App\Enums\ProductType;
use App\Filament\Admin\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('sku')->maxLength(64)->unique(ignoreRecord: true),
                Forms\Components\Select::make('type')->options(ProductType::class)->required()->live()
                    ->default(ProductType::OneTime->value),
                Forms\Components\TextInput::make('price')->numeric()->prefix(config('crm.currency', 'USD'))->required(),
                Forms\Components\Select::make('billing_interval')
                    ->options(['monthly' => 'Monthly', 'yearly' => 'Yearly'])
                    ->visible(fn (Forms\Get $get) => $get('type') === ProductType::Subscription->value),
                Forms\Components\Toggle::make('is_active')->default(true)->inline(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('sku')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('price')->money(config('crm.currency', 'USD')),
                Tables\Columns\TextColumn::make('billing_interval')->badge()->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
