<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadAssignmentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('password')
                    ->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state)),
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()->preload()->searchable()
                    ->helperText('super_admin = full control, admin = back office, sales_staff = agent panel'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Deactivated users cannot log in. Deactivate from the table to also reassign their open leads.'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-m-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->is_active && $record->id !== auth()->id())
                    ->form(fn (User $record) => [
                        Forms\Components\Placeholder::make('open_leads')
                            ->content($record->agentLeads()->open()->count().' open lead(s) will be affected.')
                            ->visible(fn () => $record->agentLeads()->open()->exists()),
                        Forms\Components\Radio::make('handling')
                            ->label('What should happen to their open leads?')
                            ->options([
                                'pool' => 'Return them to the unassigned lead pool',
                                'single' => 'Reassign all to one agent',
                                'distribute' => 'Distribute evenly across agents',
                            ])
                            ->default('pool')
                            ->live(),
                        Forms\Components\Select::make('single_agent_id')
                            ->label('Reassign all to')
                            ->options(fn () => User::activeAgents()->whereKeyNot($record->id)->pluck('name', 'id'))
                            ->searchable()->required()
                            ->visible(fn (Forms\Get $get) => $get('handling') === 'single'),
                        Forms\Components\Select::make('distribute_agents')
                            ->label('Distribute across')
                            ->options(fn () => User::activeAgents()->whereKeyNot($record->id)->pluck('name', 'id'))
                            ->multiple()->searchable()->required()
                            ->visible(fn (Forms\Get $get) => $get('handling') === 'distribute'),
                    ])
                    ->action(function (User $record, array $data) {
                        $openLeads = $record->agentLeads()->open()->get();

                        if ($openLeads->isNotEmpty()) {
                            match ($data['handling'] ?? 'pool') {
                                'single' => app(LeadAssignmentService::class)->assignAll($openLeads, User::findOrFail((int) $data['single_agent_id']), auth()->user()),
                                'distribute' => app(LeadAssignmentService::class)->distribute($openLeads, $data['distribute_agents'], auth()->user()),
                                default => $openLeads->each(fn (Lead $lead) => $lead->update(['assigned_agent_id' => null, 'assigned_at' => null])),
                            };
                        }

                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->title("{$record->name} deactivated"
                                .($openLeads->isEmpty() ? '' : ' — '.$openLeads->count().' open lead(s) handled'))
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-m-user-plus')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => ! $record->is_active)
                    ->action(function (User $record) {
                        $record->update(['is_active' => true]);

                        Notification::make()->title("{$record->name} reactivated.")->success()->send();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record) => $record->agentLeads()->doesntExist() && $record->commissions()->doesntExist() && $record->orders()->doesntExist()),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
