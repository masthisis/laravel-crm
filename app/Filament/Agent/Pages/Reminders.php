<?php

namespace App\Filament\Agent\Pages;

use App\Models\Customer;
use App\Support\Jalali;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * «یاداوری های روزانه» — my reminders for a Jalali day/range (04 §3.6).
 */
class Reminders extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'کارتابل فروش';

    protected static ?string $title = 'یاداوری های روزانه';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.agent.pages.reminders';

    public ?string $date_start = null;

    public ?string $date_end = null;

    public array $rows = [];

    public function mount(): void
    {
        $this->date_start = now()->toDateString();
        $this->date_end = now()->toDateString();
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $query = Customer::query()->where('reminder_operator_id', auth()->id());

        $start = $this->date_start !== null && $this->date_start !== ''
            ? (Jalali::parse($this->date_start) ?? Carbon::parse($this->date_start))->startOfDay()
            : now()->startOfDay();
        $end = $this->date_end !== null && $this->date_end !== ''
            ? (Jalali::parse($this->date_end) ?? Carbon::parse($this->date_end))->endOfDay()
            : now()->endOfDay();

        $this->rows = $query
            ->whereBetween('reminder_at', [$start, $end])
            ->orderBy('reminder_at')
            ->limit(200)
            ->get()
            ->map(static fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'account_status' => $c->account_status,
                'reminder_at' => $c->reminder_at,
                'url' => '/agent/customers/'.$c->id,
            ])
            ->all();
    }
}
