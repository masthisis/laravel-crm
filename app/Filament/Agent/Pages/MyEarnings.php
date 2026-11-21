<?php

namespace App\Filament\Agent\Pages;

use App\Models\CommissionEarning;
use App\Models\CommissionPayout;
use App\Models\Customer;
use App\Support\Jalali;
use App\Support\Persian;
use Filament\Pages\Page;

/**
 * Agent commission dashboard (spec 09 §1/§2.5): per-transaction rows
 * with the period decided by the payment date, totals by lead type and
 * the monthly payout history.
 */
class MyEarnings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'پورسانت';

    protected static ?string $title = 'پورسانت من';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.agent.pages.my-earnings';

    public string $month = '';

    public string $view_mode = 'current'; // current | previous | all

    public array $rows = [];

    public array $totals = [];

    public array $payoutHistory = [];

    public function mount(): void
    {
        $this->month = Jalali::monthKey(now());
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $query = CommissionEarning::query()->where('agent_id', auth()->id());

        if ($this->view_mode === 'current') {
            $query->where('month', $this->month);
        } elseif ($this->view_mode === 'previous') {
            $query->where('month', $this->previousMonth());
        }

        $rows = $query->orderByDesc('paid_at')->get();

        $this->rows = $rows->map(static fn (CommissionEarning $e) => [
            'customer' => Customer::query()->whereKey($e->customer_id)->value('name') ?? 'ناشناس',
            'code' => $e->discount_code,
            'source' => $e->source_name ?? '—',
            'rate' => Persian::toPersian((string) $e->rate).'٪',
            'paid_amount' => Persian::toman($e->paid_amount),
            'amount' => Persian::toman($e->amount),
            'paid_at' => Jalali::format($e->paid_at),
            'month' => $e->month,
        ])->all();

        $this->totals = [
            'earned' => (int) $rows->where('status', 'ok')->sum('amount'),
            'review' => $rows->where('status', 'review')->count(),
            'by_type' => $rows->groupBy(static fn (CommissionEarning $e) => $e->source_name ?? 'پیش‌فرض')
                ->map(static fn ($group) => ['count' => $group->count(), 'amount' => (int) $group->sum('amount')])
                ->all(),
        ];

        $this->payoutHistory = CommissionPayout::query()
            ->where('agent_id', auth()->id())
            ->orderByDesc('id')
            ->get()
            ->map(static fn (CommissionPayout $p) => [
                'month' => $p->month,
                'amount' => Persian::toman($p->amount),
                'paid_on' => Jalali::format($p->paid_on),
                'reference' => $p->reference,
            ])
            ->all();
    }

    private function previousMonth(): string
    {
        [$jy, $jm] = array_map('intval', explode('-', $this->month));
        $prevMonth = $jm === 1 ? 12 : $jm - 1;
        $prevYear = $jm === 1 ? $jy - 1 : $jy;

        return sprintf('%04d-%02d', $prevYear, $prevMonth);
    }
}
