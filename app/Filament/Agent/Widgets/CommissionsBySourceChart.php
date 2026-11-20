<?php

namespace App\Filament\Agent\Widgets;

use App\Models\Commission;
use Filament\Widgets\ChartWidget;

class CommissionsBySourceChart extends ChartWidget
{
    protected static ?string $heading = 'My commissions by original source';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $rows = Commission::query()
            ->where('agent_id', auth()->id())
            ->selectRaw('lead_sources.name as name, sum(commissions.amount) as total')
            ->join('lead_sources', 'lead_sources.id', '=', 'commissions.original_source_id')
            ->groupBy('lead_sources.name')
            ->orderByDesc('total')
            ->get();

        $palette = ['#6366f1', '#22c55e', '#eab308', '#ef4444', '#a855f7', '#06b6d4', '#f97316'];

        return [
            'datasets' => [
                [
                    'label' => 'Commissions',
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
