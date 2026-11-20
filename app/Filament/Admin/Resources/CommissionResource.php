<?php

namespace App\Filament\Admin\Resources;

use App\Enums\CommissionStatus;
use App\Filament\Admin\Resources\CommissionResource\Pages;
use App\Models\Commission;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CommissionResource extends Resource
{
    protected static ?string $model = Commission::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lead.name')->searchable()->label('Lead'),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent'),
                Tables\Columns\TextColumn::make('originalSource.name')->label('Original source')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('reEngagedSource.name')->label('Re-engaged')->badge()->color('purple')->placeholder('—'),
                Tables\Columns\TextColumn::make('soldSource.name')->label('Closed via')->badge()->color('success')->placeholder('—'),
                Tables\Columns\TextColumn::make('base_amount')->money(config('crm.currency', 'USD'))->label('Deal'),
                Tables\Columns\TextColumn::make('rate')->suffix('%'),
                Tables\Columns\TextColumn::make('amount')
                    ->money(config('crm.currency', 'USD'))->weight('bold'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('sold_at')->date(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CommissionStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('agent')->relationship('agent', 'name')->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-m-check')
                    ->color('info')
                    ->visible(fn (Commission $record) => $record->status === CommissionStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (Commission $record) {
                        $record->update(['status' => CommissionStatus::Approved, 'approved_at' => now()]);
                        Notification::make()->title('Commission approved.')->success()->send();
                    }),
                Tables\Actions\Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->visible(fn (Commission $record) => $record->status === CommissionStatus::Approved)
                    ->requiresConfirmation()
                    ->action(function (Commission $record) {
                        $record->update(['status' => CommissionStatus::Paid, 'paid_at' => now()]);
                        Notification::make()->title('Commission marked as paid.')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ])
            ->defaultSort('sold_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
        ];
    }
}
