<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CommissionRuleResource\Pages;
use App\Models\CommissionRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CommissionRuleResource extends Resource
{
    protected static ?string $model = CommissionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('rate')
                    ->numeric()->required()->minValue(0)->maxValue(100)
                    ->suffix('%')
                    ->helperText('Percentage of the deal amount paid to the agent.'),
                Forms\Components\TextInput::make('priority')->numeric()->default(0)
                    ->helperText('Higher priority wins ties between equally specific rules.'),
                Forms\Components\Toggle::make('is_active')->default(true)->inline(),
                Forms\Components\Textarea::make('description')->columnSpanFull(),

                Forms\Components\Section::make('Source combination')
                    ->description('Leave a field empty to match ANY source. The most specific matching rule wins.')
                    ->schema([
                        Forms\Components\Select::make('original_source_id')
                            ->relationship('originalSource', 'name')->searchable()->preload()
                            ->label('Original source (any if empty)'),
                        Forms\Components\Select::make('re_engaged_source_id')
                            ->relationship('reEngagedSource', 'name')->searchable()->preload()
                            ->label('Re-engagement source (any if empty)'),
                        Forms\Components\Select::make('sold_source_id')
                            ->relationship('soldSource', 'name')->searchable()->preload()
                            ->label('Closing source (any if empty)'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('originalSource.name')->label('Original')->badge()->placeholder('*'),
                Tables\Columns\TextColumn::make('reEngagedSource.name')->label('Re-engaged')->badge()->color('purple')->placeholder('*'),
                Tables\Columns\TextColumn::make('soldSource.name')->label('Closed via')->badge()->color('success')->placeholder('*'),
                Tables\Columns\TextColumn::make('rate')->suffix('%'),
                Tables\Columns\TextColumn::make('priority')->sortable(),
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
            ])
            ->defaultSort('priority', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionRules::route('/'),
            'create' => Pages\CreateCommissionRule::route('/create'),
            'edit' => Pages\EditCommissionRule::route('/{record}/edit'),
        ];
    }
}
