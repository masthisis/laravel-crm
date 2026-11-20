<?php

namespace App\Filament\Admin\Widgets;

use App\Models\LeadSource;
use Filament\Widgets\ChartWidget;

class LeadsBySourceChart extends ChartWidget
{
    protected static ?string $heading = 'Leads by original source';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $counts = LeadSource::query()
            ->withCount('leads')
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
