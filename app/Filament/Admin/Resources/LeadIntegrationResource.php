<?php

namespace App\Filament\Admin\Resources;

use App\Enums\IntegrationDriver;
use App\Filament\Admin\Resources\LeadIntegrationResource\Pages;
use App\Models\LeadIntegration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
                Forms\Components\Placeholder::make('token_security')
                    ->label('Token')
                    ->content('Generated automatically, stored as a SHA-256 hash and revealed exactly once — in the notification after saving. Send it as the X-Integration-Token header. Use Regenerate on the table to rotate.'),
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
                    ->label('Token (hash)')
                    ->fontFamily('mono')->limit(18)
                    ->tooltip('Only the hash is stored — regenerate to get a new plaintext token.'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('regenerate')
                    ->label('Regenerate token')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('The old token stops working immediately. The new token is shown once.')
                    ->action(function (LeadIntegration $record) {
                        $plain = Str::random(48);
                        $record->update(['token' => hash('sha256', $plain)]);

                        Notification::make()
                            ->title('New integration token — copy it now')
                            ->body($plain)
                            ->persistent()
                            ->success()
                            ->send();
                    }),
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
