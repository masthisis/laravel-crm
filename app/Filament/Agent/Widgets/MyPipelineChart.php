<?php

namespace App\Filament\Agent\Widgets;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class MyPipelineChart extends ChartWidget
{
    protected static ?string $heading = 'My pipeline by status';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $statuses = [
            LeadStatus::New->value => '#3b82f6',
            LeadStatus::Contacted->value => '#f59e0b',
            LeadStatus::Qualified->value => '#6366f1',
            LeadStatus::ReEngaged->value => '#a855f7',
            LeadStatus::Won->value => '#22c55e',
            LeadStatus::Lost->value => '#ef4444',
        ];

        $counts = Lead::query()
            ->where('assigned_agent_id', auth()->id())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => collect(array_keys($statuses))->map(fn ($status) => (int) ($counts[$status] ?? 0))->all(),
                    'backgroundColor' => array_values($statuses),
                    'borderRadius' => 4,
                ],
            ],
            'labels' => collect(array_keys($statuses))->map(fn ($status) => LeadStatus::from($status)->getLabel())->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
