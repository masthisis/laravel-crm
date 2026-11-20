<?php

namespace App\Filament\Admin\Resources;

use App\Enums\IntegrationDriver;
use App\Filament\Admin\Resources\LeadIntegrationResource\Pages;
use App\Models\LeadIntegration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Developer-facing registry of capture integrations. Every
 * integration carries a token that external systems use to push
 * leads into a specific source (webhook, embedded form, DB sync).
 */
class LeadIntegrationResource extends Resource
{
    protected static ?string $model = LeadIntegration::class;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'Sources';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('lead_source_id')
                    ->relationship('source', 'name')
                    ->searchable()->preload()->required()
                    ->label('Target source'),
                Forms\Components\Select::make('driver')
                    ->options(IntegrationDriver::class)
                    ->default(IntegrationDriver::Webhook->value)
                    ->required(),
                Forms\Components\TextInput::make('token')
                    ->default(fn () => Str::random(48))
                    ->required()->unique(ignoreRecord: true)
                    ->helperText('Send as X-Integration-Token header (API) or hidden field (embedded form).'),
                Forms\Components\Toggle::make('is_active')->default(true)->inline(),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('source.name')->label('Source')->searchable(),
                Tables\Columns\TextColumn::make('driver')->badge(),
                Tables\Columns\TextColumn::make('token')
                    ->copyable()->copyMessage('Copied')->fontFamily('mono')->limit(18),
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
            'index' => Pages\ListLeadIntegrations::route('/'),
            'create' => Pages\CreateLeadIntegration::route('/create'),
            'edit' => Pages\EditLeadIntegration::route('/{record}/edit'),
        ];
    }
}
