<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('agent_id')->relationship('agent', 'name', modifyQueryUsing: fn ($q) => $q->role('sales_staff'))->searchable()->preload()->label('Agent'),
                Forms\Components\Select::make('lead_id')->relationship('lead', 'name')->searchable()->preload(),
                Forms\Components\Select::make('source_id')->relationship('source', 'name')->searchable()->preload()->label('Sold source'),
                Forms\Components\TextInput::make('amount')->numeric()->required()->prefix(config('crm.currency', 'USD')),
                Forms\Components\DateTimePicker::make('sold_at')->default(now())->required(),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->searchable(),
                Tables\Columns\TextColumn::make('product.name'),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->toggleable(),
                Tables\Columns\TextColumn::make('source.name')->label('Sold source')->badge(),
                Tables\Columns\TextColumn::make('amount')->money(config('crm.currency', 'USD')),
                Tables\Columns\TextColumn::make('sold_at')->date()->sortable(),
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
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
