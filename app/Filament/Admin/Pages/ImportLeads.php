<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AssignmentStrategy;
use App\Models\AssignmentBatch;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadImportService;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class ImportLeads extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Leads';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.admin.pages.import-leads';

    /** @var array<int, AssignmentBatch> */
    public array $recentBatches = [];

    public function mount(): void
    {
        $this->recentBatches = AssignmentBatch::latest()->limit(10)->get()->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-m-arrow-up-tray')
                ->button()
                ->form([
                    FileUpload::make('csv')
                        ->label('CSV file')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(20480)
                        ->required()
                        ->helperText('Header row required. Columns: name (required), phone, email, source, estimated_value. Rows with a "source" value get that source created automatically.'),
                    Select::make('source_id')
                        ->label('Default source')
                        ->options(LeadSource::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->helperText('Used for rows without their own "source" value.'),
                    TextInput::make('new_source_name')
                        ->label('Or create a new source')
                        ->helperText('Ignored when a default source is picked or rows carry their own source.'),
                    Radio::make('strategy')
                        ->label('Assignment after import')
                        ->options(AssignmentStrategy::class)
                        ->default(AssignmentStrategy::None->value)
                        ->live(),
                    Select::make('single_agent_id')
                        ->label('Assign all to')
                        ->options(fn () => User::role(Roles::SALES_STAFF)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->visible(fn (Get $get) => $get('strategy') === AssignmentStrategy::Single->value)
                        ->required(fn (Get $get) => $get('strategy') === AssignmentStrategy::Single->value),
                    Select::make('distribute_agents')
                        ->label('Distribute across')
                        ->options(fn () => User::role(Roles::SALES_STAFF)->orderBy('name')->pluck('name', 'id'))
                        ->multiple()->searchable()
                        ->visible(fn (Get $get) => $get('strategy') === AssignmentStrategy::Distribute->value)
                        ->required(fn (Get $get) => $get('strategy') === AssignmentStrategy::Distribute->value)
                        ->helperText('Leads are spread evenly, balancing each agent’s current workload.'),
                ])
                ->action(function (array $data) {
                    $result = app(LeadImportService::class)->import(
                        Storage::disk('local')->path($data['csv']),
                        $data['source_id'] ?? null,
                        $data['new_source_name'] ?? null,
                        auth()->id(),
                        AssignmentStrategy::from($data['strategy']),
                        $data['single_agent_id'] ?? null,
                        $data['distribute_agents'] ?? [],
                    );

                    $this->recentBatches = AssignmentBatch::latest()->limit(10)->get()->all();

                    Notification::make()
                        ->title("Imported {$result['imported']} lead(s), skipped {$result['skipped']}.")
                        ->body(implode("\n", array_slice($result['errors'], 0, 5)))
                        ->success()->send();
                }),
        ];
    }

    public function getHeading(): string
    {
        return 'Lead import & assignment';
    }
}
