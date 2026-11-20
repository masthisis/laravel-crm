<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\SyncRunResource\Pages;
use App\Models\SyncRun;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Leads';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('connection_name')->label('Connection')->badge(),
                Tables\Columns\TextColumn::make('trigger')->badge()
                    ->color(fn (SyncRun $record) => $record->trigger === 'schedule' ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('dry_run')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn (SyncRun $record) => $record->dry_run ? 'Dry run' : 'Live'),
                Tables\Columns\TextColumn::make('seen')->sortable(),
                Tables\Columns\TextColumn::make('imported')->color('success')->sortable(),
                Tables\Columns\TextColumn::make('skipped')->color('warning'),
                Tables\Columns\TextColumn::make('failed')->color(fn (SyncRun $record) => $record->failed > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('last_ref')->label('Cursor')->toggleable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('connection_name')->label('Connection'),
            ])
            ->recordUrl(null)
            ->poll('30s')
            ->actions([
                Tables\Actions\Action::make('errors')
                    ->icon('heroicon-m-list-bullet')
                    ->color('gray')
                    ->visible(fn (SyncRun $record) => $record->getAttribute('errors') !== null)
                    ->modalHeading('Row errors')
                    ->modalContent(fn (SyncRun $record) => view('filament.admin.sync-run-errors', [
                        'errors' => (array) $record->getAttribute('errors'),
                    ])),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
        ];
    }
}
