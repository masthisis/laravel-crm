<?php

namespace App\Filament\Agent\Resources;

use App\Enums\CommissionStatus;
use App\Filament\Agent\Resources\MyCommissionResource\Pages;
use App\Models\Commission;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyCommissionResource extends Resource
{
    protected static ?string $model = Commission::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('agent_id', auth()->id()))
            ->columns([
                Tables\Columns\TextColumn::make('lead.name')->label('Lead')->searchable(),
                Tables\Columns\TextColumn::make('base_amount')->money(config('crm.currency', 'USD'))->label('Deal'),
                Tables\Columns\TextColumn::make('rate')->suffix('%'),
                Tables\Columns\TextColumn::make('amount')->money(config('crm.currency', 'USD'))->weight('bold'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('sold_at')->date(),
            ])
            ->groups([
                Group::make('originalSource.name')->label('Original source')->collapsible(),
                Group::make('soldSource.name')->label('Closing source')->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CommissionStatus::class)->multiple(),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('sold_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMyCommissions::route('/'),
        ];
    }
}
