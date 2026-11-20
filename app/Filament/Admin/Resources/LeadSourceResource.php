<?php

namespace App\Filament\Admin\Resources;

use App\Enums\SourceType;
use App\Filament\Admin\Resources\LeadSourceResource\Pages;
use App\Models\LeadSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class LeadSourceResource extends Resource
{
    protected static ?string $model = LeadSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Sources';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                Forms\Components\TextInput::make('slug')
                    ->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\Select::make('type')
                    ->options(SourceType::class)
                    ->default(SourceType::Manual->value)
                    ->required(),
                Forms\Components\ColorPicker::make('color')->default('#6366f1'),
                Forms\Components\Toggle::make('is_active')->default(true)->inline(),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()->label('Type'),
                Tables\Columns\TextColumn::make('leads_count')->counts('leads')->label('Leads'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(SourceType::class),
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
            'index' => Pages\ListLeadSources::route('/'),
            'create' => Pages\CreateLeadSource::route('/create'),
            'edit' => Pages\EditLeadSource::route('/{record}/edit'),
        ];
    }
}
