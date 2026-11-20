<?php

namespace App\Filament\Admin\Widgets;

use App\Models\LeadSource;
use Filament\Widgets\ChartWidget;

class LeadsBySourceChart extends ChartWidget
{
    protected static ?string $heading = 'Leads by original source';

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

        $counts = LeadSource::query()
            ->withCount(['leads' => fn ($query) => $cutoff === null ? $query : $query->where('created_at', '>=', $cutoff)])
            ->orderByDesc('leads_count')
            ->limit(10)
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => $counts->pluck('leads_count')->all(),
                    'backgroundColor' => $counts->pluck('color')->all(),
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $counts->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
