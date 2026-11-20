<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class RevenueBySourceChart extends ChartWidget
{
    protected static ?string $heading = 'Revenue by sold source';

    protected int|string|array $columnSpan = 1;

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            'all' => 'All time',
        ];
    }

    protected function periodCutoff(): ?string
    {
        return $this->filter === null || $this->filter === 'all'
            ? null
            : now()->subDays((int) $this->filter)->startOfDay()->toDateTimeString();
    }

    protected function getData(): array
    {
        $cutoff = $this->periodCutoff();

        $rows = Order::query()
            ->when($cutoff !== null, fn ($query) => $query->where('sold_at', '>=', $cutoff))
            ->selectRaw('lead_sources.name as name, sum(orders.amount) as total')
            ->join('lead_sources', 'lead_sources.id', '=', 'orders.source_id')
            ->groupBy('lead_sources.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $palette = ['#6366f1', '#22c55e', '#eab308', '#ef4444', '#a855f7', '#06b6d4', '#f97316', '#ec4899', '#84cc16', '#64748b'];

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $rows->pluck('total')->map(fn ($v) => (float) $v)->all(),
                    'backgroundColor' => collect($palette)->take(max(1, $rows->count()))->all(),
                ],
            ],
            'labels' => $rows->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }
}
